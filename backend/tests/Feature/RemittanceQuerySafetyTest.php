<?php

namespace Tests\Feature;

use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Permission\RemittancePermission;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RemittanceQuerySafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private RemittanceOrder $order;

    private int $couponId;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'log', 'cache.default' => 'array',
            'yaband_remit.base_url' => 'https://query.example.test',
            'yaband_remit.mid' => 'fixture-mid', 'yaband_remit.password' => 'fixture-password',
        ]);
        $this->member = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($this->member->id), '101', null);
        $this->order = RemittanceOrder::query()->forceCreate([
            'reference' => (string) Str::uuid(), 'member_id' => $this->member->id,
            'provider_trade_id' => 'fixture-trade', 'white_label' => false, 'principal_minor' => 10000,
            'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00',
        ]);
        $coupon = app(RemittanceCouponRepository::class)->issue((string) Str::uuid(), [
            'member_id' => $this->member->id, 'issue_fingerprint' => 'query-safety',
            'title' => '查单验证券', 'discount_bps' => 3000, 'expires_at' => now()->addDay(),
        ]);
        $coupon->forceFill(['order_reference' => $this->order->reference])->saveOrFail();
        $this->couponId = $coupon->id;
        $operator = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->withToken($operator->createToken('platform', [RemittancePermission::SYNC_ORDERS])->plainTextToken);
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'fixture-signature';
            }
        });
        Http::preventStrayRequests();
    }

    #[DataProvider('invalidAmountsAndCurrencies')]
    public function test_query_cannot_confirm_payment_or_refund_with_mismatched_metadata(bool $whiteLabel, array $changes): void
    {
        $this->order->forceFill(['white_label' => $whiteLabel])->saveOrFail();
        foreach (['00', '04'] as $refund) {
            $this->fakeQuery([...$changes, 'refund_status_code' => $refund]);
            $this->postJson($this->refreshPath())->assertStatus(502);
            $this->assertOriginalState();
        }
    }

    public static function invalidAmountsAndCurrencies(): iterable
    {
        $cases = [
            'different amount' => ['amount' => '99.99'], 'null amount' => ['amount' => null],
            'integer amount' => ['amount' => 100], 'float amount' => ['amount' => 100.0],
            'array amount' => ['amount' => ['100.00']], 'exponent amount' => ['amount' => '1e2'],
            'excess decimal precision' => ['amount' => '100.000'], 'trailing amount space' => ['amount' => '100.00 '],
            'different currency' => ['currency' => 'CNY'], 'null currency' => ['currency' => null],
            'numeric currency' => ['currency' => 978], 'array currency' => ['currency' => ['EUR']],
        ];
        foreach ([false, true] as $whiteLabel) {
            foreach ($cases as $name => $changes) {
                yield ($whiteLabel ? 'white label ' : 'institution ').$name => [$whiteLabel, $changes];
            }
        }
    }

    #[DataProvider('supportedResponses')]
    public function test_matching_or_legacy_query_keeps_notification_and_coupon_idempotency(bool $whiteLabel, bool $legacy): void
    {
        $this->order->forceFill(['white_label' => $whiteLabel])->saveOrFail();
        $this->fakeQuery([], $legacy);
        $this->postJson($this->refreshPath())->assertOk()->assertJsonPath('data.status_label', '已下发');
        $this->postJson($this->refreshPath())->assertOk()->assertJsonPath('data.status_label', '已下发');
        $this->assertNotNull($this->order->fresh()->provider_checked_at);
        $this->assertDatabaseMissing('remittance_coupons', ['id' => $this->couponId, 'redeemed_at' => null]);
        $this->assertDatabaseCount('member_notifications', 1);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), $whiteLabel
            ? '/api/whitelable/transaction/query' : '/api/transaction/query'));
    }

    public static function supportedResponses(): array
    {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    #[DataProvider('unknownStatuses')]
    public function test_unknown_provider_status_does_not_commit_a_partial_funds_result(array $changes): void
    {
        $this->fakeQuery($changes);
        $this->postJson($this->refreshPath())->assertStatus(502);
        $this->assertOriginalState();
    }

    public static function unknownStatuses(): array
    {
        return [
            [['income_status_code' => '99']],
            [['outgoing_status_code' => '99']],
            [['refund_status_code' => '99']],
        ];
    }

    public function test_cancelled_refund_is_explicit_in_staff_and_member_order_views(): void
    {
        $this->fakeQuery(['refund_status_code' => '07']);
        $this->postJson($this->refreshPath())->assertOk()->assertJsonPath('data.status_label', '退款已取消');
        $this->app['auth']->forgetGuards();
        $this->withToken($this->member->createToken('member')->plainTextToken)
            ->getJson('/app/remittance/orders/'.$this->order->reference)
            ->assertOk()->assertJsonPath('data.status_label', '退款已取消');
        $this->assertDatabaseCount('member_notifications', 0);
    }

    #[DataProvider('unknownStatuses')]
    public function test_unknown_historical_status_does_not_display_completed_funds(array $changes): void
    {
        $this->order->forceFill([
            'income_code' => $changes['income_status_code'] ?? '00',
            'outgoing_code' => $changes['outgoing_status_code'] ?? '05',
            'refund_code' => $changes['refund_status_code'] ?? '00',
        ])->saveOrFail();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->member->createToken('member')->plainTextToken)
            ->getJson('/app/remittance/orders/'.$this->order->reference)
            ->assertOk()->assertJsonPath('data.status_label', '等待核对状态');
    }

    private function fakeQuery(array $changes = [], bool $legacy = false): void
    {
        $transaction = [
            'mid' => 'fixture-mid', 'uid' => '101', 'order_id' => $this->order->reference,
            'trade_id' => 'fixture-trade', 'amount' => '100.00', 'currency' => 'EUR',
            'income_status_code' => '00', 'outgoing_status_code' => '05', 'refund_status_code' => '00',
            ...$changes,
        ];
        if ($legacy) {
            unset($transaction['amount'], $transaction['currency']);
        }
        Http::fake([
            '*/api/auth/login' => Http::response(['status' => true, 'code' => 200, 'data' => [
                'token' => 'fixture-token', 'token_type' => 'Bearer',
                'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp,
            ]]),
            '*/transaction/query' => Http::response(['status' => true, 'code' => 200, 'data' => ['transaction_info' => $transaction]]),
        ]);
    }

    private function assertOriginalState(): void
    {
        $this->assertDatabaseHas('remittance_orders', [
            'reference' => $this->order->reference, 'income_code' => '03',
            'outgoing_code' => '01', 'refund_code' => '00', 'provider_checked_at' => null,
        ]);
        $this->assertNotNull($this->order->fresh()->provider_sync_attempted_at);
        $this->assertDatabaseHas('remittance_coupons', [
            'id' => $this->couponId, 'order_reference' => $this->order->reference, 'redeemed_at' => null,
        ]);
        $this->assertDatabaseCount('member_notifications', 0);
    }

    private function refreshPath(): string
    {
        return '/admin/remittance/orders/'.$this->order->reference.'/refresh';
    }
}
