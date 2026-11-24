<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitClient;
use App\Service\Remittance\Yaband\YabandTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InstitutionPaymentFixture;
use Tests\TestCase;

final class YabandTokenRecoveryTest extends TestCase
{
    private string $cacheKey;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array',
            'yaband_remit.base_url' => 'https://recovery.example.test',
            'yaband_remit.mid' => 'fixture-mid',
            'yaband_remit.password' => 'fixture-password',
            'yaband_remit.secret_key' => 'fixture-secret',
        ]);
        $this->app->register(AppServiceProvider::class, true);
        $this->cacheKey = 'yaband-remit:bearer:'.hash('sha256', 'https://recovery.example.test|fixture-mid');
        Http::preventStrayRequests();
        $next = 0;
        Http::fake(['*/api/auth/login' => static function () use (&$next) {
            return Http::response(['status' => true, 'code' => 200, 'data' => [
                'token' => ++$next === 1 ? 'rejected-token' : 'replacement-token', 'token_type' => 'bearer',
                'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp,
            ]]);
        }]);
    }

    #[DataProvider('authenticationFailures')]
    public function test_rejected_token_is_invalidated_without_replaying_payment(int $status, array $body): void
    {
        Http::fake(['*/api/transaction/payment' => Http::response($body, $status)]);
        $this->assertSame('rejected-token', app(YabandTransport::class)->token());

        try {
            app(YabandRemitClient::class)->submitPayment(InstitutionPaymentFixture::request(), false);
            $this->fail('Expected the authentication failure to be returned.');
        } catch (YabandException) {
            // 本次支付必须失败，不能通过自动重放掩盖未知处理结果。
        }
        Http::assertSentCount(2);
        $this->assertSame('replacement-token', app(YabandTransport::class)->token());
        $this->assertSame('replacement-token', app(YabandTransport::class)->token());
        Http::assertSentCount(3);
        $this->assertCount(1, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/transaction/payment')));
    }

    public static function authenticationFailures(): iterable
    {
        yield 'HTTP401' => [401, ['message' => 'Unauthenticated.']];
        yield 'HTTP200 numeric code' => [200, ['status' => false, 'code' => 401, 'message' => 'Invalid Token']];
        yield 'HTTP200 string code' => [200, ['status' => false, 'code' => '401', 'message' => 'Invalid Token']];
    }

    public function test_late_401_does_not_delete_a_concurrently_replaced_token(): void
    {
        Http::fake(['*/api/transaction/query' => function () {
            Cache::put($this->cacheKey, Crypt::encryptString('newer-concurrent-token'), 300);

            return Http::response(['message' => 'Unauthenticated.'], 401);
        }]);
        $this->assertSame('rejected-token', app(YabandTransport::class)->token());

        $this->rejectRequest('/api/transaction/query', 'rejected-token');
        $this->assertSame('newer-concurrent-token', app(YabandTransport::class)->token());
        Http::assertSentCount(2);
    }

    public function test_rejected_token_is_removed_even_when_the_401_body_is_not_json(): void
    {
        Http::fake(['*/api/whitelable/transaction/queryExchangeRate' => Http::response('<html>Unauthorized</html>', 401)]);
        $this->assertSame('rejected-token', app(YabandTransport::class)->token());
        $this->rejectRequest('/api/whitelable/transaction/queryExchangeRate', 'rejected-token', true);
        $this->assertSame('replacement-token', app(YabandTransport::class)->token());
    }

    #[DataProvider('nonAuthenticationFailures')]
    public function test_business_signature_errors_forbidden_and_server_errors_keep_the_token(int $status, array $body): void
    {
        Http::fake(['*/api/transaction/query' => Http::response($body, $status)]);
        $this->assertSame('rejected-token', app(YabandTransport::class)->token());
        $this->rejectRequest('/api/transaction/query', 'rejected-token');
        $this->assertSame('rejected-token', app(YabandTransport::class)->token());
        Http::assertSentCount(2);
    }

    public static function nonAuthenticationFailures(): iterable
    {
        yield 'HTTP200 signature failure' => [200, ['status' => false, 'code' => 401, 'message' => 'Invalid signature']];
        yield 'HTTP403 permissions' => [403, ['message' => 'Forbidden']];
        yield 'HTTP500' => [500, ['message' => 'Server error']];
    }

    public function test_rejected_bearer_does_not_clear_another_merchant_cache(): void
    {
        $other = 'yaband-remit:bearer:'.hash('sha256', 'https://recovery.example.test|other-mid');
        Cache::put($other, Crypt::encryptString('other-merchant-token'), 300);
        $this->assertSame('rejected-token', app(YabandTransport::class)->token());
        Http::fake(['*/api/transaction/query' => Http::response([], 401)]);
        $this->rejectRequest('/api/transaction/query', 'rejected-token');
        $this->assertSame('other-merchant-token', Crypt::decryptString(Cache::get($other)));
        $this->assertSame('replacement-token', app(YabandTransport::class)->token());
    }

    public function test_request_without_bearer_cannot_evict_the_cached_token(): void
    {
        Http::fake(['*/api/other' => Http::response(['status' => false, 'code' => 401, 'message' => 'Invalid Token'])]);
        $transport = app(YabandTransport::class);
        $this->assertSame('rejected-token', $transport->token());
        try {
            $transport->post('/api/other', []);
            $this->fail('Expected an upstream failure.');
        } catch (YabandException) {
        }
        $this->assertSame('rejected-token', $transport->token());
        Http::assertSentCount(2);
    }

    private function rejectRequest(string $path, string $token, bool $decimals = false): void
    {
        try {
            app(YabandTransport::class)->post($path, [], $token, $decimals);
            $this->fail('Expected the upstream failure to be returned.');
        } catch (YabandException) {
            // 同一次请求不重试，下一次显式调用才允许刷新 Token。
        }
    }
}
