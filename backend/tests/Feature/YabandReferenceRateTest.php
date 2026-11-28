<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use App\Repository\Remittance\RemittanceRuleRepository;
use App\Service\Remittance\Yaband\Contract\YabandRateLock;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class YabandReferenceRateTest extends TestCase
{
    use RefreshDatabase;

    private const INPUT = ['principal_minor' => 100000, 'payment_method' => 'card', 'payout_method' => 'bank'];

    private string $rawRate = '7.357323184199999';

    private string $source = 'EUR';

    private string $target = 'CNY';

    private bool $offline = false;

    private int $queries = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config([
            'yaband_remit.base_url' => 'https://rates.example.test', 'yaband_remit.mid' => 'fixture-mid',
            'yaband_remit.password' => 'fixture-password', 'yaband_remit.reference_rate_enabled' => true,
        ]);
        $this->app->instance('env', 'production');
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'fixture-signature';
            }
        });
        app(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599, 'first_order_free' => false, 'spread_basis_points' => 100,
            'payment' => ['card' => ['basis_points' => 80]],
            'eta' => ['bank' => ['threshold_minor' => 100000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $this->withToken(User::factory()->create()->createToken('member')->plainTextToken);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/api/auth/login')) {
                return Http::response(['status' => true, 'code' => 200, 'data' => [
                    'token' => 'fixture-token', 'expires_in' => 3600, 'expires_at' => now()->addHour()->timestamp, 'token_type' => 'Bearer',
                ]]);
            }
            $this->assertSame('https://rates.example.test/api/whitelable/transaction/queryExchangeRate', $request->url());
            $this->assertSame('fixture-mid', $request['mid']);
            $this->assertSame('fixture-signature', $request['sign']);
            $this->assertTrue(Str::isUuid($request['data']['request_id']));
            $this->assertSame('EUR', $request['data']['source_currency']);
            $this->assertSame('CNY', $request['data']['target_currency']);
            $this->assertArrayNotHasKey('type', $request['data']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer fixture-token'));
            $this->queries++;
            if ($this->offline) {
                return Http::failedConnection();
            }

            return Http::response('{"status":true,"code":200,"data":{"source_currency":"'.$this->source.'","target_currency":"'.$this->target.'","exchange_rate":'.$this->rawRate.',"response_id":"fixture-response"}}');
        });
    }

    public function test_live_reference_rate_uses_server_rules_and_remains_a_non_payable_estimate_and_draft(): void
    {
        $record = $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertOk()
            ->assertJsonPath('data.rate_source', 'yaband_reference')->assertJsonPath('data.reference_rate_observed_at', now()->timestamp)
            ->assertJsonPath('data.rate_ten_thousandths', 72837)->assertJsonPath('data.payout_minor', 728370)
            ->assertJsonPath('data.mode', 'local_estimate')->assertJsonPath('data.can_submit', false)
            ->assertJsonMissingPath('data.source_rate_ten_thousandths')->assertJsonMissingPath('data.spread_basis_points')
            ->assertJsonMissingPath('data.provider_lock_reference')->json('data.record_no');
        $saved = app(RemittanceQuoteRecordRepository::class)->findByReference($record);
        $this->assertSame(73573, $saved->snapshot['source_rate_ten_thousandths']);
        $this->putJson('/api/app/remittance/drafts/'.$record)->assertOk();
        $this->postJson('/api/app/remittance/orders', ['quote_reference' => $record, 'payout_account' => (string) Str::uuid()])->assertConflict();
        $this->assertDatabaseCount('remittance_orders', 0);
        Http::assertSentCount(2);
    }

    #[DataProvider('validRates')]
    public function test_decimal_rate_is_quantized_without_float_loss(string $raw, int $expected): void
    {
        $this->rawRate = $raw;
        $record = $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertOk()->json('data.record_no');
        $this->assertSame($expected, app(RemittanceQuoteRecordRepository::class)->findByReference($record)->snapshot['source_rate_ten_thousandths']);
    }

    public static function validRates(): array
    {
        return [
            ['7.357323184199999', 73573], ['"7.3573"', 73573], ['7', 70000],
            ['7.12345', 71235], ['7.123449999999999999999999999999', 71234],
            ['0.00005', 1], ['100', 1000000],
        ];
    }

    #[DataProvider('invalidRates')]
    public function test_invalid_rate_does_not_create_a_quote_or_fall_back_to_local_settings(string $raw): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $this->rawRate = $raw;
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertServiceUnavailable()->assertJsonPath('code', 50301);
        $this->assertDatabaseCount('remittance_quote_records', 0);
    }

    public static function invalidRates(): array
    {
        return array_map(fn ($rate) => [$rate], ['null', 'true', 'false', '[]', '{}', '0', '-7.3', '100.0001', '0.00004', '1e309', '"7.3abc"', '" 7.30"', '"NaN"']);
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        $this->source = 'USD';
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertServiceUnavailable();
        $this->source = 'EUR';
        $this->target = 'USD';
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertServiceUnavailable();
        $this->assertDatabaseCount('remittance_quote_records', 0);
    }

    public function test_cache_expires_without_changing_the_observed_time_and_expired_failure_is_not_hidden(): void
    {
        $observed = now()->timestamp;
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertOk()->assertJsonPath('data.reference_rate_observed_at', $observed);
        $this->travel(59)->seconds();
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertOk()->assertJsonPath('data.reference_rate_observed_at', $observed);
        $this->assertSame(1, $this->queries);
        $this->travel(1)->seconds();
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertOk()->assertJsonPath('data.reference_rate_observed_at', $observed + 60);
        $this->assertSame(2, $this->queries);
        $this->travel(60)->seconds();
        $this->offline = true;
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertServiceUnavailable();
        $this->assertDatabaseCount('remittance_quote_records', 3);
        $this->assertSame(3, $this->queries);
    }

    public function test_cached_reference_rate_does_not_acquire_refresh_lock(): void
    {
        $observed = now()->timestamp;
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertOk();
        $this->travelBack();
        $lock = Cache::lock('yaband-remit:reference-rate:'.hash('sha256', 'https://rates.example.test|fixture-mid').':lock', 15);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/api/app/remittance/quotes', self::INPUT)
                ->assertOk()->assertJsonPath('data.reference_rate_observed_at', $observed);
        } finally {
            $lock->release();
        }

        $this->assertSame(1, $this->queries);
    }

    public function test_disabling_reference_rates_or_missing_signer_does_not_make_outbound_calls(): void
    {
        config(['yaband_remit.reference_rate_enabled' => false]);
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertServiceUnavailable();
        config(['yaband_remit.reference_rate_enabled' => true]);
        $this->app->offsetUnset(YabandRemitSigner::class);
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_existing_locked_rate_adapter_keeps_priority_over_reference_rates(): void
    {
        $this->app->instance(YabandRateLock::class, new class implements YabandRateLock
        {
            public function lock(int $member, array $request): array
            {
                return ['reference' => 'confirmed-lock', 'expires_at' => now()->addMinute()->timestamp, 'rate_ten_thousandths' => 80540];
            }
        });
        $this->postJson('/api/app/remittance/quotes', self::INPUT)->assertOk()
            ->assertJsonPath('data.mode', 'locked')->assertJsonPath('data.can_submit', true);
        Http::assertNothingSent();
    }

    public function test_decimal_preservation_does_not_rewrite_string_values_or_integer_metadata(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $escaped = 'a\\"7.35e0';
        Http::fake(['https://rates.example.test/decimal-probe' => Http::response(
            '{"status":true,"code":200,"data":{"rate":7.123449999999999999999999,"count":5,"escaped":'.json_encode($escaped, JSON_THROW_ON_ERROR).',"text":"7.12","object":{"fraction":0.20}}}'
        )]);
        $reply = app(YabandTransport::class)->post('/decimal-probe', [], preserveDecimals: true);
        $this->assertSame('7.123449999999999999999999', $reply['rate']);
        $this->assertSame(5, $reply['count']);
        $this->assertSame($escaped, $reply['escaped']);
        $this->assertSame('7.12', $reply['text']);
        $this->assertSame('0.20', $reply['object']['fraction']);
    }

    public function test_malformed_json_is_not_repaired_by_decimal_preservation(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://rates.example.test/decimal-probe' => Http::response(
            '{"status":true,"code":200,"data":{"rate":7.35,"invalid":01.234}}'
        )]);
        $this->expectException(YabandException::class);
        app(YabandTransport::class)->post('/decimal-probe', [], preserveDecimals: true);
    }
}
