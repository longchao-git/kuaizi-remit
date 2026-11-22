<?php

namespace Tests\Feature;

use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\Contract\YabandCheckoutTerms;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class YabandCheckoutReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private array $input;

    private int $couponId;

    private array $kyc = [
        'uid' => '101', 'user_status_code' => '1001', 'remit_status_code' => '4001',
        'kyc_status_code' => '5004', 'idv_status_code' => '2006', 'profile_status_code' => '3003',
    ];

    private array $paymentChanges = [];

    private array $omitPaymentFields = [];

    private bool $kycOffline = false;

    private int $kycDelay = 0;

    private int $kycQueries = 0;

    private object $terms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config([
            'broadcasting.default' => 'log', 'yaband_remit.base_url' => 'https://checkout.example.test',
            'yaband_remit.mid' => 'fixture-mid', 'yaband_remit.password' => 'fixture-password',
        ]);
        $this->member = User::factory()->create();
        $members = app(YabandRemitMemberRepository::class);
        $members->saveState($members->getOrCreate($this->member->id), '101', ['kyc_passed' => true, 'remittance_enabled' => true]);
        $coupon = app(RemittanceCouponRepository::class)->issue((string) Str::uuid(), [
            'member_id' => $this->member->id, 'issue_fingerprint' => 'checkout-readiness', 'title' => '测试券',
            'discount_bps' => 3000, 'expires_at' => now()->addDay(),
        ]);
        $this->couponId = $coupon->id;
        $quote = app(RemittanceQuoteRecordRepository::class)->record($this->member->id, [
            'rule_revision_id' => null, 'principal_minor' => 10000, 'payout_minor' => 80540,
            'rate_ten_thousandths' => 80540, 'base_fee_minor' => 100, 'discount_minor' => 30,
            'channel_fee_minor' => 80, 'total_payable_minor' => 10150, 'payment_method' => 'card', 'payout_method' => 'bank',
            'expires_at' => now()->addMinutes(2)->timestamp, 'mode' => 'locked', 'can_submit' => true, 'coupon_id' => $coupon->id,
        ]);
        $this->input = ['quote_reference' => $quote->reference, 'payout_account' => (string) Str::uuid()];
        $this->withToken($this->member->createToken('member')->plainTextToken);
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'fixture-signature';
            }
        });
        $this->terms = new class implements YabandCheckoutTerms
        {
            public bool $whiteLabel = false;

            public int $prepares = 0;

            public ?Closure $onPrepare = null;

            public function accounts(User $member, string $payoutMethod): array
            {
                return [];
            }

            public function prepare(User $member, array $snapshot, string $payoutAccount, string $ip): array
            {
                $this->prepares++;
                if ($this->onPrepare !== null) {
                    ($this->onPrepare)();
                }

                return ['white_label' => $this->whiteLabel, 'fields' => ['payee' => '{"account":"fixture-account"}']];
            }
        };
        $this->app->instance(YabandCheckoutTerms::class, $this->terms);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/api/auth/login')) {
                $data = ['token' => 'fixture-token', 'expires_in' => 3600, 'expires_at' => now()->addHour()->timestamp, 'token_type' => 'Bearer'];
            } elseif (str_ends_with($request->url(), '/api/kyc/query')) {
                $this->kycQueries++;
                if ($this->kycDelay > 0) {
                    $this->travel($this->kycDelay)->seconds();
                }
                if ($this->kycOffline) {
                    return Http::failedConnection();
                }
                $data = $this->kyc;
            } else {
                $this->assertContains($request->url(), [
                    'https://checkout.example.test/api/transaction/payment',
                    'https://checkout.example.test/api/whitelable/transaction/payment',
                ]);
                $data = [...[
                    'mid' => 'fixture-mid', 'type' => 'transaction', 'uid' => '101',
                    'order_id' => $request['data']['order_id'], 'trade_id' => 'trade-'.$request['data']['order_id'],
                    'amount' => '100.00', 'currency' => 'EUR', 'url' => 'https://checkout.example.test/pay',
                ], ...$this->paymentChanges];
                foreach ($this->omitPaymentFields as $field) {
                    unset($data[$field]);
                }
            }

            return Http::response(['status' => true, 'code' => 200, 'data' => $data]);
        });
    }

    #[DataProvider('ineligibleKyc')]
    public function test_cached_approval_cannot_override_current_ineligible_or_incomplete_kyc(array $changes): void
    {
        $this->kyc = [...$this->kyc, ...$changes];
        $this->postJson('/api/app/remittance/orders', $this->input)->assertConflict();
        $this->assertSame(1, $this->kycQueries);
        $this->assertSame(0, $this->terms->prepares);
        $this->assertNothingReservedOrPaid();
        if (($changes['kyc_status_code'] ?? null) === '5003') {
            $this->assertDatabaseHas('member_notifications', ['member_id' => $this->member->id, 'title' => '请更新身份认证资料']);
        }
    }

    public static function ineligibleKyc(): array
    {
        return [
            'expired-document' => [['kyc_status_code' => '5003', 'remit_status_code' => '4002', 'idv_status_code' => '2002']],
            'kyc-rejected' => [['kyc_status_code' => '5005']],
            'user-inactive' => [['user_status_code' => '1002']],
            'remittance-disabled' => [['remit_status_code' => '4002']],
            'incomplete-kyc' => [['kyc_status_code' => null]],
            'incomplete-remittance' => [['remit_status_code' => null]],
            'unknown-kyc' => [['kyc_status_code' => '5999']],
        ];
    }

    public function test_failed_kyc_query_or_wrong_uid_does_not_create_a_payment_or_reserve_a_coupon(): void
    {
        $this->kycOffline = true;
        $this->postJson('/api/app/remittance/orders', $this->input)->assertStatus(502);
        $this->assertNothingReservedOrPaid();
        $this->kycOffline = false;
        $this->kyc['uid'] = 'another-member';
        $this->postJson('/api/app/remittance/orders', $this->input)->assertStatus(502);
        $this->assertNothingReservedOrPaid();
        $this->assertDatabaseCount('member_notifications', 0);
    }

    public function test_fresh_approval_allows_payment_and_saved_trade_retry_does_not_create_or_query_again(): void
    {
        $members = app(YabandRemitMemberRepository::class);
        $members->saveState($members->findFor($this->member->id), '101', ['kyc_passed' => false, 'remittance_enabled' => false]);
        $reference = $this->postJson('/api/app/remittance/orders', $this->input)->assertOk()
            ->assertJsonPath('data.payment_url', 'https://checkout.example.test/pay')->json('data.reference');
        $this->assertSame(1, $this->kycQueries);
        $this->assertTrue($members->findFor($this->member->id)->statuses['kyc_passed']);
        $this->postJson('/api/app/remittance/orders', $this->input)->assertOk()->assertJsonPath('data.reference', $reference);
        $this->assertSame(1, $this->kycQueries);
        $this->assertCount(1, $this->payments());
        $this->assertDatabaseCount('remittance_orders', 1);
        $this->assertDatabaseHas('remittance_coupons', ['id' => $this->couponId, 'order_reference' => $reference, 'redeemed_at' => null]);
    }

    #[DataProvider('invalidPaymentMetadata')]
    public function test_mismatched_payment_response_is_not_associated_or_replayed(bool $whiteLabel, array $changes): void
    {
        $this->terms->whiteLabel = $whiteLabel;
        $this->paymentChanges = $changes;
        $this->postJson('/api/app/remittance/orders', $this->input)->assertStatus(502);
        $reserved = DB::table('remittance_orders')->first();
        $this->assertNotNull($reserved);
        $this->assertNull($reserved->provider_trade_id);
        $this->assertNull($reserved->payment_url);
        $this->assertSame('02', $reserved->income_code);
        $this->assertNull($reserved->provider_checked_at);
        $this->assertDatabaseHas('remittance_coupons', ['id' => $this->couponId, 'order_reference' => $reserved->reference, 'redeemed_at' => null]);
        $this->assertDatabaseCount('member_notifications', 0);
        $requestId = $reserved->payment_request_id;
        $this->postJson('/api/app/remittance/orders', $this->input)->assertConflict();
        $this->assertCount(1, $this->payments());
        $this->assertDatabaseHas('remittance_orders', ['reference' => $reserved->reference, 'payment_request_id' => $requestId]);
    }

    public static function invalidPaymentMetadata(): iterable
    {
        $cases = [
            'other-user' => ['uid' => 'another-member'], 'null-user' => ['uid' => null],
            'array-user' => ['uid' => ['101']], 'boolean-user' => ['uid' => true],
            'other-amount' => ['amount' => '1.00'], 'null-amount' => ['amount' => null],
            'numeric-amount' => ['amount' => 100.0], 'exponential-amount' => ['amount' => '1e2'],
            'whitespace-amount' => ['amount' => '100.00 '], 'other-currency' => ['currency' => 'CNY'],
            'null-currency' => ['currency' => null], 'numeric-currency' => ['currency' => 978],
        ];
        foreach ([false, true] as $whiteLabel) {
            foreach ($cases as $name => $changes) {
                yield ($whiteLabel ? 'whitelabel-' : 'institution-').$name => [$whiteLabel, $changes];
            }
        }
    }

    public function test_matching_numeric_uid_remains_supported(): void
    {
        $this->paymentChanges = ['uid' => 101];
        $this->postJson('/api/app/remittance/orders', $this->input)->assertOk();
        $this->assertCount(1, $this->payments());
        $this->assertDatabaseCount('member_notifications', 0);
    }

    public function test_legacy_response_without_optional_metadata_remains_supported(): void
    {
        $this->omitPaymentFields = ['uid', 'amount', 'currency'];
        $this->postJson('/api/app/remittance/orders', $this->input)->assertOk();
        $this->assertCount(1, $this->payments());
    }

    #[DataProvider('expiryStages')]
    public function test_quote_expiry_during_external_preparation_stops_before_reserving_or_paying(string $stage): void
    {
        if ($stage === 'kyc') {
            $this->kycDelay = 120;
        } else {
            $this->terms->onPrepare = fn () => $this->travel(120)->seconds();
        }
        $this->postJson('/api/app/remittance/orders', $this->input)->assertConflict()->assertJsonPath('msg', '锁汇已过期，请重新报价');
        $this->assertNothingReservedOrPaid();
        $this->assertSame(1, $this->kycQueries);
    }

    public static function expiryStages(): array
    {
        return [['kyc'], ['payee']];
    }

    public function test_quote_expiry_while_reserving_stops_before_payment_and_keeps_the_order_reference(): void
    {
        RemittanceOrder::created(fn () => $this->travel(120)->seconds());
        $this->postJson('/api/app/remittance/orders', $this->input)->assertConflict()->assertJsonPath('msg', '锁汇已过期，请重新报价');
        $this->assertCount(0, $this->payments());
        $reserved = DB::table('remittance_orders')->first();
        $this->assertNotNull($reserved);
        $this->assertNull($reserved->provider_trade_id);
        $this->assertNull($reserved->payment_url);
        $this->assertDatabaseHas('remittance_coupons', ['id' => $this->couponId, 'order_reference' => $reserved->reference, 'redeemed_at' => null]);
        $this->assertDatabaseCount('member_notifications', 0);
    }

    private function assertNothingReservedOrPaid(): void
    {
        $this->assertDatabaseCount('remittance_orders', 0);
        $this->assertDatabaseHas('remittance_coupons', ['id' => $this->couponId, 'order_reference' => null, 'redeemed_at' => null]);
        $this->assertCount(0, $this->payments());
    }

    private function payments(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/transaction/payment'))->values()->all();
    }
}
