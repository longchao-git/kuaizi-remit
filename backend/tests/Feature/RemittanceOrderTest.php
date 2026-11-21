<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\RemittancePermission;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Service\Notification\MemberNotificationService;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RemittanceOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_only_see_their_own_orders_and_staff_permissions_split_read_and_sync(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $order = $this->seedOrder($alice);
        $otherOrder = $this->seedOrder($bob);
        $this->withToken($alice->createToken('member')->plainTextToken)
            ->getJson('/api/app/remittance/orders')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.reference', $order);
        $this->getJson('/api/app/remittance/orders/'.$order)->assertOk()
            ->assertJsonMissingPath('data.trade_id')->assertJsonMissingPath('data.member_id');
        $this->getJson('/api/app/remittance/orders/'.$otherOrder)->assertNotFound();
        $this->getJson('/api/admin/remittance/orders')->assertForbidden();

        $readOnly = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'read_only']);
        $this->app['auth']->forgetGuards();
        $this->withToken($readOnly->createToken('platform', [RemittancePermission::VIEW_ORDERS])->plainTextToken)
            ->getJson('/api/admin/remittance/orders?member_id='.$bob->id)->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.reference', $otherOrder);
        $this->postJson('/api/admin/remittance/orders/'.$order.'/refresh')->assertForbidden();
    }

    public function test_staff_can_refresh_existing_trade_and_mismatched_or_unsigned_result_does_not_change_it(): void
    {
        $member = User::factory()->create();
        $operator = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $reference = $this->seedOrder($member);
        $unpaid = $this->seedOrder($member);
        $coupon = app(RemittanceCouponRepository::class)->issue((string) Str::uuid(), [
            'member_id' => $member->id, 'issue_fingerprint' => 'discount', 'title' => '折扣券',
            'discount_bps' => 3000, 'expires_at' => now()->addDay(),
        ]);
        $coupon->forceFill(['order_reference' => $unpaid])->saveOrFail();
        $trade = 'trade-'.$reference;
        $path = '/api/admin/remittance/orders/'.$reference.'/refresh';
        $this->withToken($operator->createToken('platform', [RemittancePermission::SYNC_ORDERS])->plainTextToken);
        Http::preventStrayRequests();
        $this->postJson($path)->assertServiceUnavailable();
        Http::assertNothingSent();

        config(['yaband_remit.base_url' => 'https://sandbox.example.test', 'yaband_remit.mid' => '900001', 'yaband_remit.password' => 'test-password']);
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'test-signature';
            }
        });
        $state = [
            'order_id' => $reference, 'trade_id' => $trade,
            'income_status_code' => '00', 'outgoing_status_code' => '06', 'refund_status_code' => '04',
        ];
        Http::fake([
            'https://sandbox.example.test/api/auth/login' => Http::response($this->providerReply([
                'token' => 'test-token', 'expires_in' => 3600, 'expires_at' => now()->addHour()->timestamp, 'token_type' => 'Bearer',
            ])),
            'https://sandbox.example.test/api/transaction/query' => Http::sequence()
                ->push($this->providerReply(['transaction_info' => [...$state, 'trade_id' => 'wrong']]))
                ->push($this->providerReply(['transaction_info' => $state]))
                ->push($this->providerReply(['transaction_info' => $state]))
                ->push($this->providerReply(['transaction_info' => [
                    'order_id' => $unpaid, 'trade_id' => 'trade-'.$unpaid,
                    'income_status_code' => '07', 'outgoing_status_code' => '01', 'refund_status_code' => '00',
                ]])),
        ]);
        $this->postJson($path)->assertStatus(502);
        $this->assertDatabaseHas('remittance_orders', ['reference' => $reference, 'income_code' => '03', 'provider_checked_at' => null]);
        $this->postJson($path)->assertOk()->assertJsonPath('data.status_label', '已退款');
        $this->assertDatabaseHas('remittance_orders', ['reference' => $reference, 'income_code' => '00', 'outgoing_code' => '06', 'refund_code' => '04']);
        $this->app->instance(YabandRemitCallbackVerifier::class, new class implements YabandRemitCallbackVerifier
        {
            public function verifies(string $event, string $body, array $payload): bool
            {
                return $event === 'transaction' && $payload['sign'] === 'verified-fixture';
            }
        });
        $notice = [
            'type' => 'transaction', 'mid' => '900001', 'trade_id' => $trade, 'order_id' => $reference,
            'refund_status_code' => '00',
        ];
        $callback = '/api/callbacks/yaband/remit/transaction';
        $this->postJson($callback, ['sign' => 'verified-fixture', 'data' => [...$notice, 'trade_id' => 'wrong']])->assertNotFound();
        $this->postJson($callback, ['sign' => 'verified-fixture', 'data' => $notice])->assertOk()->assertContent('ok');
        $this->assertDatabaseHas('remittance_orders', ['reference' => $reference, 'refund_code' => '04']);
        $this->assertDatabaseCount('member_notifications', 1);
        $this->assertDatabaseHas('member_notifications', [
            'member_id' => $member->id, 'title' => '汇款订单已退款', 'order_reference' => $reference,
        ]);
        $this->postJson('/api/admin/remittance/orders/'.$unpaid.'/refresh')->assertOk();
        $this->assertDatabaseHas('remittance_coupons', ['id' => $coupon->id, 'order_reference' => null, 'redeemed_at' => null]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://sandbox.example.test/api/transaction/query'
            && $request['data']['trade_id'] === $trade && $request->hasHeader('Authorization', 'Bearer test-token'));
    }

    public function test_query_rejects_foreign_or_invalid_nested_merchant_without_changing_orders_or_notices(): void
    {
        config(['broadcasting.default' => 'log']);
        config(['yaband_remit.base_url' => 'https://sandbox.example.test', 'yaband_remit.mid' => '900001', 'yaband_remit.password' => 'test-password']);
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'test-signature';
            }
        });
        Http::preventStrayRequests();
        $member = User::factory()->create();
        $operator = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->withToken($operator->createToken('platform', [RemittancePermission::SYNC_ORDERS])->plainTextToken);

        foreach ([false, true] as $whiteLabel) {
            $reference = $this->seedOrder($member);
            DB::table('remittance_orders')->where('reference', $reference)->update(['white_label' => $whiteLabel]);
            $path = '/api/admin/remittance/orders/'.$reference.'/refresh';
            $state = [
                'order_id' => $reference, 'trade_id' => 'trade-'.$reference,
                'income_status_code' => '00', 'outgoing_status_code' => '05', 'refund_status_code' => '00',
            ];
            $replies = Http::sequence();
            foreach (['900002', 900002, null, ['900001'], 900001, '900001'] as $mid) {
                $replies->push($this->providerReply([
                    'mid' => '900001', 'transaction_info' => [...$state, 'mid' => $mid],
                ]));
            }
            $queryPath = $whiteLabel ? '/api/whitelable/transaction/query' : '/api/transaction/query';
            Http::fake([
                'https://sandbox.example.test/api/auth/login' => Http::response($this->providerReply([
                    'token' => 'test-token', 'expires_in' => 3600, 'expires_at' => now()->addHour()->timestamp, 'token_type' => 'Bearer',
                ])),
                'https://sandbox.example.test'.$queryPath => $replies,
            ]);
            $notices = $whiteLabel ? 1 : 0;
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $this->postJson($path)->assertServiceUnavailable();
                $this->assertDatabaseHas('remittance_orders', [
                    'reference' => $reference, 'income_code' => '03', 'outgoing_code' => '01', 'provider_checked_at' => null,
                ]);
                $this->assertDatabaseCount('member_notifications', $notices);
            }
            $this->postJson($path)->assertOk()->assertJsonPath('data.status_label', '已下发');
            $this->assertDatabaseHas('remittance_orders', [
                'reference' => $reference, 'income_code' => '00', 'outgoing_code' => '05',
            ]);
            $this->assertDatabaseCount('member_notifications', $notices + 1);
            $this->postJson($path)->assertOk();
            $this->assertDatabaseCount('member_notifications', $notices + 1);
        }
    }

    public function test_order_notice_links_only_owned_orders_and_preserves_idempotency_and_read_state(): void
    {
        config(['broadcasting.default' => 'log']);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $reference = $this->seedOrder($member);
        $otherOrder = $this->seedOrder($other);
        $otherOwnOrder = $this->seedOrder($member);
        $notices = app(MemberNotificationService::class);
        $input = ['request_id' => (string) Str::uuid(), 'title' => '汇款已下发', 'body' => '正文不含订单号', 'order_reference' => $reference];
        $notification = $notices->send($member->id, null, $input, broadcast: false)->getData();
        $this->assertSame($notification['id'], $notices->send($member->id, null, $input, broadcast: false)->getData()['id']);
        $this->assertSame(409, $notices->send($member->id, null, [...$input, 'order_reference' => $otherOwnOrder], broadcast: false)->getCode());
        $this->assertSame(404, $notices->send($member->id, null, [...$input, 'order_reference' => $otherOrder], broadcast: false)->getCode());
        $this->assertDatabaseCount('member_notifications', 1);

        $noticePath = '/api/app/notifications/'.$notification['id'];
        $orderPath = '/api/app/remittance/orders/'.$reference;
        $this->withToken($other->createToken('member')->plainTextToken);
        $this->getJson($noticePath)->assertNotFound();
        $this->getJson($orderPath)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('member')->plainTextToken);
        $this->getJson('/api/app/notifications')->assertOk()->assertJsonPath('data.list.0.order_reference', $reference);
        $this->getJson($noticePath)->assertOk()->assertJsonPath('data.order_reference', $reference);
        $this->postJson($noticePath.'/read')->assertOk()->assertJsonPath('data.unread_count', 0);
        $this->getJson($orderPath)->assertOk()->assertJsonPath('data.reference', $reference);
        $this->assertDatabaseMissing('member_notifications', ['id' => $notification['id'], 'read_at' => null]);
    }

    private function seedOrder(User $member): string
    {
        $reference = (string) Str::uuid();
        DB::table('remittance_orders')->insert([
            'reference' => $reference, 'provider_trade_id' => 'trade-'.$reference, 'member_id' => $member->id,
            'white_label' => false, 'principal_minor' => 10000, 'income_code' => '03',
            'outgoing_code' => '01', 'refund_code' => '00', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $reference;
    }

    private function providerReply(array $data): array
    {
        return ['status' => true, 'code' => 200, 'data' => $data];
    }
}
