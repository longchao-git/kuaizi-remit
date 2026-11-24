<?php

namespace Tests\Feature;

use App\Service\Remittance\Yaband\Contract\YabandCheckoutTerms;
use App\Service\Remittance\Yaband\Contract\YabandRateLock;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitClient;
use App\Service\Remittance\Yaband\YabandRemitHmacSigner;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstitutionPaymentFixture;
use Tests\TestCase;

final class YabandInstitutionPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array', 'yaband_remit.base_url' => 'https://institution.example.test',
            'yaband_remit.mid' => 'fixture-mid', 'yaband_remit.password' => 'fixture-password',
        ]);
        $this->app->instance(YabandRemitSigner::class, new YabandRemitHmacSigner('fixture-secret'));
        Http::preventStrayRequests();
    }

    public function test_institution_route_signs_and_sends_the_same_nested_json_strings(): void
    {
        Http::fake([
            '*/api/auth/login' => Http::response(['status' => true, 'code' => 200, 'data' => [
                'token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 3600, 'expires_at' => now()->addHour()->timestamp,
            ]]),
            '*/api/transaction/payment' => Http::response(['status' => true, 'code' => 200, 'data' => ['trade_id' => 'fixture-trade']]),
        ]);
        $input = InstitutionPaymentFixture::request();
        $input['payee'] = json_encode($input['payee'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $result = app(YabandRemitClient::class)->submitPayment($input, false);
        $this->assertSame('fixture-trade', $result['trade_id']);
        Http::assertSent(function (Request $request) use ($input): bool {
            if ($request->url() !== 'https://institution.example.test/api/transaction/payment') {
                return false;
            }
            $body = json_decode($request->body(), true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame($input['payee'], $body['data']['payee']);
            $this->assertIsString($body['data']['device']);
            $this->assertSame('iDEAL', $body['data']['sub_pay_method']);
            $this->assertSame('online', $body['data']['pay_method']);
            $this->assertSame('transaction', $body['data']['type']);
            $parameters = [...$body['data'], 'mid' => $body['mid'], 'time' => $body['time']];
            ksort($parameters);
            $pairs = [];
            foreach ($parameters as $key => $value) {
                $pairs[] = $key.'='.(string) $value;
            }
            $this->assertSame(hash_hmac('sha256', implode('&', $pairs), 'fixture-secret'), $body['sign']);

            return $request->hasHeader('Authorization', 'Bearer fixture-token');
        });
        Http::assertSentCount(2);
    }

    public function test_invalid_institution_request_is_rejected_before_authentication_or_network(): void
    {
        $input = InstitutionPaymentFixture::request();
        $input['payee']['account'] = '';
        try {
            app(YabandRemitClient::class)->submitPayment($input, false);
            $this->fail('Invalid payee was sent.');
        } catch (YabandException) {
            Http::assertNothingSent();
        }
    }

    public function test_protocol_support_does_not_enable_unconfirmed_business_adapters(): void
    {
        $this->assertFalse($this->app->bound(YabandCheckoutTerms::class));
        $this->assertFalse($this->app->bound(YabandRateLock::class));
        $this->assertFalse($this->app->bound(YabandRemitCallbackVerifier::class));
    }
}
