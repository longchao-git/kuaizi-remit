<?php

namespace Tests\Feature;

use App\Model\Notification\MemberNotification;
use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Service\Remittance\RemittanceOrderService;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderNotificationAtomicityTest extends TestCase
{
    use RefreshDatabase;

    public function test_notice_conflict_rolls_back_order_and_coupon_then_retry_creates_only_one_status_notice(): void
    {
        config(['broadcasting.default' => 'log', 'yaband_remit.base_url' => 'https://fixture.example.test',
            'yaband_remit.mid' => 'fixture-mid', 'yaband_remit.password' => 'fixture-only']);
        Http::preventStrayRequests();
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'fixture-signature';
            }
        });
        $member = User::factory()->create();
        $order = RemittanceOrder::forceCreate([
            'reference' => (string) Str::uuid(), 'provider_trade_id' => 'fixture-trade', 'member_id' => $member->id,
            'principal_minor' => 10000, 'white_label' => true, 'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00',
        ]);
        $coupon = app(RemittanceCouponRepository::class)->issue((string) Str::uuid(), [
            'member_id' => $member->id, 'issue_fingerprint' => 'notice-atomicity', 'title' => '隔离测试券',
            'discount_bps' => 3000, 'expires_at' => now()->addDay(),
        ]);
        $coupon->forceFill(['order_reference' => $order->reference])->saveOrFail();
        Http::fake([
            '*/api/auth/login' => Http::response(['status' => true, 'code' => 200, 'data' => [
                'token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp,
            ]]),
            '*/api/whitelable/transaction/query' => Http::response(['status' => true, 'code' => 200, 'data' => ['transaction_info' => [
                'order_id' => $order->reference, 'trade_id' => 'fixture-trade', 'income_status_code' => '00',
                'outgoing_status_code' => '05', 'refund_status_code' => '00',
            ]]]),
        ]);
        $fixed = '11c41b40-67c8-4b62-a7c3-51e8a026d94a';
        MemberNotification::create(['member_id' => $member->id, 'request_id' => $fixed, 'title' => '冲突提醒', 'body' => '原消息不能被覆盖']);
        Str::createUuidsUsing(fn () => $fixed);
        try {
            $failed = app(RemittanceOrderService::class)->refresh($order->reference);
            $this->assertSame(503, $failed->getCode());
            $this->assertSame('03', $order->fresh()->income_code);
            $this->assertSame('01', $order->fresh()->outgoing_code);
            $this->assertNull($order->fresh()->provider_checked_at);
            $this->assertNull($coupon->fresh()->redeemed_at);
            $this->assertSame($order->reference, $coupon->fresh()->order_reference);
            $this->assertDatabaseCount('member_notifications', 1);
        } finally {
            Str::createUuidsUsing(null);
        }
        $this->assertTrue(app(RemittanceOrderService::class)->refresh($order->reference)->isSuccess());
        $this->assertSame('00', $order->fresh()->income_code);
        $this->assertNotNull($coupon->fresh()->redeemed_at);
        $this->assertTrue(app(RemittanceOrderService::class)->refresh($order->reference)->isSuccess());
        $this->assertDatabaseCount('member_notifications', 2);
        $this->assertDatabaseHas('member_notifications', ['member_id' => $member->id, 'title' => '汇款已下发', 'order_reference' => $order->reference]);
    }
}
