<?php

namespace Tests\Feature;

use App\Model\System\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

final class MemberDeviceSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_signatures_bind_body_route_session_and_nonce(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.require_device_signatures' => true, 'remit.local_login_email' => 'signed@example.test']);
        User::factory()->create(['email' => 'signed@example.test', 'password' => 'signed-test-password']);
        $credentials = ['email' => 'signed@example.test', 'password' => 'signed-test-password'];
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $key);
        $point = openssl_pkey_get_details($key)['ec'];
        $public = base64_encode("\x04".str_pad($point['x'], 32, "\0", STR_PAD_LEFT).str_pad($point['y'], 32, "\0", STR_PAD_LEFT));
        $this->postJson('/app/auth/local-login', [...$credentials, 'device_public_key' => str_repeat('A', 88)])->assertUnprocessable();
        $token = $this->postJson('/app/auth/local-login', [...$credentials, 'device_public_key' => $public])->assertOk()->json('data.token');
        $other = $this->postJson('/app/auth/local-login', [...$credentials, 'device_public_key' => $public])->assertOk()->json('data.token');
        $path = '/app/remittance/payout-account-drafts/9c2ae982-9f68-4ba4-b9b1-e77409d41837';
        $body = json_encode(['payout_method' => 'bank', 'first_name' => '测试', 'last_name' => 'Fixture', 'account' => 'not-a-real-bank-8877'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $nonce = str_repeat('a', 32);
        $timestamp = now()->timestamp;
        $headers = $this->sign($key, $path, $body, $token, $timestamp, $nonce);

        $this->send($path, $body, $token, [])->assertForbidden();
        $this->send($path, $body.' ', $token, $headers)->assertForbidden();
        $this->send('/api'.$path, $body, $token, $headers)->assertForbidden();
        $this->send($path, $body, $other, $headers)->assertForbidden();
        $expired = $this->sign($key, $path, $body, $token, $timestamp - 91, $nonce);
        $this->send($path, $body, $token, $expired)->assertForbidden();
        $this->assertDatabaseCount('member_payout_account_drafts', 0);

        $this->send($path, $body, $token, $headers)->assertOk()->assertJsonPath('data.masked_account', '****8877');
        $this->send($path, $body, $token, $headers)->assertConflict();
        $aliasProof = $this->sign($key, '/api'.$path, $body, $token, $timestamp, $nonce);
        $this->send('/api'.$path, $body, $token, $aliasProof)->assertConflict();
        $this->assertDatabaseCount('member_payout_account_drafts', 1);
    }

    private function sign(OpenSSLAsymmetricKey $key, string $path, string $body, string $token, int $time, string $nonce): array
    {
        $message = implode("\n", ['remit-v1', 'PUT', $path, hash('sha256', $body), hash('sha256', $token), (string) $time, $nonce]);
        $this->assertTrue(openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256));

        return ['HTTP_X_REMIT_TIME' => (string) $time, 'HTTP_X_REMIT_NONCE' => $nonce, 'HTTP_X_REMIT_SIGNATURE' => base64_encode($signature)];
    }

    private function send(string $path, string $body, string $token, array $headers): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call('PUT', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            ...$headers,
        ], $body);
    }
}
