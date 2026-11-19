<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Providers\AppServiceProvider;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitClient;
use App\Service\Remittance\Yaband\YabandTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class YabandRemitClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'yaband_remit.base_url' => 'https://remit.example.test',
            'yaband_remit.mid' => 'fixture-mid',
            'yaband_remit.password' => 'fixture-api-password',
        ]);
        Http::preventStrayRequests();
    }

    public function test_token_uses_project_login_and_refreshes_before_expiry(): void
    {
        $this->fakeLogin(['first-token', 'next-token']);
        $transport = app(YabandTransport::class);
        $this->assertSame('first-token', $transport->token());
        $this->assertSame('first-token', $transport->token());
        Http::assertSentCount(1);
        $this->travel(91)->seconds();
        $this->assertSame('next-token', $transport->token());
        Http::assertSentCount(2);
    }

    public function test_connection_check_reports_signing_readiness_without_showing_credentials(): void
    {
        config(['yaband_remit.secret_key' => 'fixture-signing-secret']);
        $this->app->register(AppServiceProvider::class, true);
        $this->fakeLogin(['fixture-token']);

        $this->artisan('remit:check-yaband')
            ->expectsOutput('雅本 Token 鉴权通过。')
            ->expectsOutput('签名密钥：已配置')
            ->expectsOutput('请求签名：已注册')
            ->expectsOutput('回调验签：待实现')
            ->doesntExpectOutputToContain('fixture-token')
            ->doesntExpectOutputToContain('fixture-signing-secret')
            ->doesntExpectOutputToContain('fixture-api-password')
            ->assertSuccessful();
        Http::assertSentCount(1);
    }

    public function test_unreadable_cached_token_is_replaced_before_a_signed_request(): void
    {
        $this->fakeLogin(['first-token', 'replacement-token']);
        $transport = app(YabandTransport::class);
        $this->assertSame('first-token', $transport->token());
        $cacheKey = 'yaband-remit:bearer:'.hash('sha256', 'https://remit.example.test|fixture-mid');
        Cache::put($cacheKey, 'invalid-encrypted-cache', 60);

        $this->assertSame('replacement-token', $transport->token());
        $this->assertSame('replacement-token', $transport->token());
        Http::assertSentCount(2);
    }

    public function test_a_token_for_a_different_merchant_is_never_cached(): void
    {
        Http::fake(['*/api/auth/login' => Http::response([
            'status' => true, 'code' => 200, 'data' => [
                'token' => 'wrong-merchant-token', 'token_type' => 'bearer', 'expires_in' => 120,
                'expires_at' => now()->addMinutes(2)->timestamp, 'merchant' => ['mid' => 'other-mid'],
            ],
        ])]);

        try {
            app(YabandTransport::class)->token();
            $this->fail('Expected the token merchant to be checked.');
        } catch (YabandException $error) {
            $this->assertSame('雅本 Token 响应的商户编号不匹配。', $error->getMessage());
        }
        $cacheKey = 'yaband-remit:bearer:'.hash('sha256', 'https://remit.example.test|fixture-mid');
        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_large_numeric_user_identifiers_are_preserved_when_creating_a_member(): void
    {
        config(['yaband_remit.secret_key' => 'fixture-signing-secret']);
        $this->app->register(AppServiceProvider::class, true);
        $this->fakeLogin(['fixture-token']);
        Http::fake(['*/api/kyc/new' => Http::response(
            '{"status":true,"code":200,"data":{"user":{"uid":9223372036854775808,"external_uid":"member-fixture"}}}',
            200, ['Content-Type' => 'application/json'],
        )]);

        $uid = app(YabandRemitClient::class)->createMember('fixture-request', 'https://app.example.test/notify', [
            'external_uid' => 'member-fixture', 'email' => 'member@example.test',
        ]);
        $this->assertSame('9223372036854775808', $uid);
    }

    public function test_signed_responses_from_a_different_merchant_or_business_are_rejected(): void
    {
        config(['yaband_remit.secret_key' => 'fixture-signing-secret']);
        $this->app->register(AppServiceProvider::class, true);
        $this->fakeLogin(['fixture-token']);
        Http::fake(['*/api/kyc/query' => Http::sequence()
            ->push(['status' => true, 'code' => 200, 'data' => ['mid' => 'other-mid', 'uid' => '1001']])
            ->push(['status' => true, 'code' => 200, 'data' => ['mid' => 'fixture-mid', 'type' => 'transaction', 'uid' => '1001']])]);

        foreach (['雅本响应的商户编号不匹配。', '雅本响应的业务类型不匹配。'] as $message) {
            try {
                app(YabandRemitClient::class)->queryKyc('fixture-request', '1001');
                $this->fail('Expected the mismatched response to be rejected.');
            } catch (YabandException $error) {
                $this->assertSame($message, $error->getMessage());
            }
        }
    }

    public function test_member_creation_cannot_bind_another_external_user(): void
    {
        config([
            'yaband_remit.secret_key' => 'fixture-signing-secret',
            'yaband_remit.kyc_redirect_url' => 'https://app.example.test/return',
            'yaband_remit.kyc_notify_url' => 'https://app.example.test/notify',
            'yaband_remit.external_uid_prefix' => 'fixture',
        ]);
        $this->app->register(AppServiceProvider::class, true);
        $this->fakeLogin(['fixture-token']);
        Http::fake(['*/api/kyc/new' => Http::response([
            'status' => true, 'code' => 200,
            'data' => ['user' => ['uid' => '1001', 'external_uid' => 'another-member']],
        ])]);

        $member = User::factory()->create();
        $this->withToken($member->createToken('member')->plainTextToken)
            ->postJson('/api/app/kyc/idv')->assertStatus(502);
        $this->assertDatabaseHas('yaband_remit_members', ['member_id' => $member->id, 'uid' => null]);
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/api/kyc/idv'));
    }

    public function test_idv_cannot_open_a_session_returned_for_another_user(): void
    {
        config(['yaband_remit.secret_key' => 'fixture-signing-secret']);
        $this->app->register(AppServiceProvider::class, true);
        $this->fakeLogin(['fixture-token']);
        Http::fake(['*/api/kyc/idv' => Http::response([
            'status' => true, 'code' => 200,
            'data' => ['url' => 'https://idv.example.test/session', 'user' => ['uid' => '1002']],
        ])]);

        $this->expectException(YabandException::class);
        $this->expectExceptionMessage('雅本身份验证响应的用户编号不匹配。');
        app(YabandRemitClient::class)->startIdv('fixture-request', '1001', 'https://app.example.test/return', 'https://app.example.test/notify');
    }

    public function test_configured_signer_is_used_by_the_normal_client_without_enabling_callback_verification(): void
    {
        config(['yaband_remit.secret_key' => 'fixture-signing-secret']);
        $this->app->register(AppServiceProvider::class, true);
        $this->assertFalse($this->app->bound(YabandRemitCallbackVerifier::class));
        $this->fakeLogin(['fixture-token']);
        Http::fake(['*/api/kyc/query' => Http::response([
            'status' => true, 'code' => 200,
            'data' => ['mid' => 'fixture-mid', 'type' => 'kyc', 'uid' => '1001', 'kyc_status_code' => '5001'],
        ])]);

        app(YabandRemitClient::class)->queryKyc('fixture-request', '1001');

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/api/kyc/query')) {
                return false;
            }
            $canonical = 'mid=fixture-mid&request_id=fixture-request&time='.$request['time'].'&type=kyc&user={"uid":"1001"}';

            return $request->hasHeader('Authorization', 'Bearer fixture-token')
                && $request['sign'] === hash_hmac('sha256', $canonical, 'fixture-signing-secret');
        });
    }

    public function test_kyc_and_payment_follow_documented_paths_and_preserve_nested_json_strings(): void
    {
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return $path.'|'.($payload['data']['request_id'] ?? '');
            }
        });
        $this->fakeLogin(['fixture-token']);
        $fake = static fn (array $data) => Http::response(['status' => true, 'code' => '200', 'data' => $data]);
        Http::fake([
            '*/api/kyc/new' => $fake(['user' => ['uid' => 1000000001]]),
            '*/api/kyc/idv' => $fake(['url' => 'https://idv.example.test/session', 'user' => ['uid' => 1000000001]]),
            '*/api/kyc/query' => $fake([
                'uid' => 1000000001, 'user_status_code' => '1001', 'remit_status_code' => '4002',
                'kyc_status_code' => '5002', 'idv_status_code' => '2006', 'profile_status_code' => '3001',
                'user' => '{"id_number":"fixture-document"}',
            ]),
            '*/api/whitelable/transaction/payment' => $fake(['trade_id' => 'test-trade']),
            '*/api/whitelable/transaction/query' => $fake(['transaction_info' => ['refund_status_code' => '02']]),
            '*/api/materials/update' => $fake(['qno' => 'question-1']),
        ]);
        $client = app(YabandRemitClient::class);
        $notify = 'https://app.example.test/kyc/notification';
        $this->assertSame('1000000001', $client->createMember('create-id', $notify, ['external_uid' => 'member-1', 'email' => 'member@example.test']));
        $this->assertSame('https://idv.example.test/session', $client->startIdv('idv-id', '1000000001', 'https://app.example.test/kyc/return', $notify));
        $kyc = $client->queryKyc('kyc-id', '1000000001');
        $this->assertFalse($kyc['kyc_passed']);
        $this->assertFalse($kyc['remittance_enabled']);
        $this->assertTrue($kyc['can_submit_profile']);
        $this->assertArrayNotHasKey('user', $kyc);

        $payee = '{"first_name":"测试","account":"fixture"}';
        $this->assertSame(['trade_id' => 'test-trade'], $client->submitPayment(['request_id' => 'pay-id', 'payee' => $payee], true));
        $this->assertSame('02', $client->queryTransaction('order-id', 'test-trade', true)['transaction_info']['refund_status_code']);
        $this->assertSame('question-1', $client->submitMaterials('mat-id', 'question-1', 'fixture reply')['qno']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/whitelable/transaction/payment')
            && $request->hasHeader('Authorization', 'Bearer fixture-token')
            && $request['sign'] === '/api/whitelable/transaction/payment|pay-id'
            && $request['data']['payee'] === $payee
            && $request['data']['type'] === 'transaction'
            && $request['data']['pay_method'] === 'online');
    }

    public function test_signed_operations_stop_before_network_without_a_project_signer(): void
    {
        try {
            app(YabandRemitClient::class)->queryKyc('test-id', '1000000001');
            $this->fail('Expected the request to stop when the signer is missing.');
        } catch (YabandException $exception) {
            $this->assertSame('雅本汇款项目签名尚未配置。', $exception->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_member_kyc_saves_the_provider_id_and_queries_without_using_return_parameters(): void
    {
        config([
            'yaband_remit.kyc_redirect_url' => 'https://app.example.test/kyc/return',
            'yaband_remit.kyc_notify_url' => 'https://app.example.test/kyc/notify',
            'yaband_remit.external_uid_prefix' => 'fixture',
        ]);
        $member = User::factory()->create();
        $path = '/api/app/kyc';
        $this->withToken($member->createToken('app')->plainTextToken)->postJson($path.'/idv')->assertServiceUnavailable();
        Http::assertNothingSent();
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'fixture-signature';
            }
        });
        $this->fakeLogin(['fixture-token']);
        Http::fake([
            '*/api/kyc/new' => Http::response(['status' => true, 'code' => '200', 'data' => ['user' => ['uid' => '1001']]]),
            '*/api/kyc/idv' => Http::response(['status' => true, 'code' => '200', 'data' => ['url' => 'https://idv.example.test/session']]),
            '*/api/kyc/query' => Http::sequence()
                ->push(['status' => true, 'code' => '200', 'data' => ['uid' => '1001', 'kyc_status_code' => '5001']])
                ->push(['status' => true, 'code' => '200', 'data' => ['uid' => '1001', 'kyc_status_code' => '5001', 'idv_status_code' => '2003']])
                ->push(['status' => true, 'code' => '200', 'data' => ['uid' => '1001', 'kyc_status_code' => '5002', 'idv_status_code' => '2006']])
                ->push(['status' => true, 'code' => '200', 'data' => ['uid' => '1001', 'kyc_status_code' => '5002', 'idv_status_code' => '2006']]),
            '*/api/kyc/profile' => Http::response([
                'status' => true, 'code' => 200, 'data' => ['uid' => '1001', 'kyc_status_code' => 5002, 'profile_status_code' => 3002],
            ]),
        ]);

        $profile = [
            'first_name' => 'Test', 'last_name' => 'User', 'nationality' => 'CN', 'id_type' => 'passport',
            'id_number' => 'FIXTURE', 'expiry_date' => '2099-01-01', 'birthday' => '1990-01-01', 'gender' => 'F',
            'address' => ['country' => 'ES', 'address_line1' => 'Example', 'post_code' => '28001', 'city' => 'Madrid'],
        ];
        $this->getJson($path.'/status')->assertJsonPath('data.registered', false);
        $this->postJson($path.'/idv')->assertOk()->assertJsonPath('data.url', 'https://idv.example.test/session');
        $this->postJson($path.'/profile', $profile)->assertConflict();
        $this->getJson($path.'/status?status=pass')->assertOk()
            ->assertJsonPath('data.registered', true)->assertJsonPath('data.kyc_passed', false)
            ->assertJsonPath('data.idv_status_code', '2006');
        $this->postJson($path.'/profile', $profile)->assertOk()
            ->assertJsonPath('data.profile_status_code', '3002')->assertJsonPath('data.kyc_passed', false);
        $this->assertDatabaseHas('yaband_remit_members', ['member_id' => $member->id, 'uid' => '1001']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/kyc/new')
            && $request['data']['user']['external_uid'] === 'fixture-'.$member->id
            && $request['data']['user']['email'] === $member->email);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/kyc/profile')
            && $request['data']['user']['uid'] === '1001'
            && $request['data']['user']['address']['country'] === 'ES');
    }

    private function fakeLogin(array $tokens): void
    {
        Http::fake(['*/api/auth/login' => function (Request $request) use (&$tokens) {
            $this->assertSame('fixture-mid', $request['mid']);
            $this->assertSame('fixture-api-password', $request['password']);

            return Http::response([
                'status' => true, 'code' => 200,
                'data' => [
                    'token' => array_shift($tokens), 'token_type' => 'bearer',
                    'expires_in' => 120, 'expires_at' => now()->timestamp + 120,
                ],
            ]);
        }]);
    }
}
