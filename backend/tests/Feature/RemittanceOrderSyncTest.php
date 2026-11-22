<?php

namespace Tests\Feature;

use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\RemittanceOrderService;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RemittanceOrderSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config([
            'broadcasting.default' => 'log',
            'yaband_remit.base_url' => 'https://sync.example.test',
            'yaband_remit.mid' => 'fixture-mid',
            'yaband_remit.password' => 'fixture-password',
            'yaband_remit.order_sync' => ['enabled' => false, 'interval_seconds' => 300, 'batch_size' => 10],
        ]);
        Http::preventStrayRequests();
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'fixture-signature';
            }
        });
    }

    public function test_only_due_unsettled_orders_with_a_trade_are_queried_in_both_modes(): void
    {
        $member = $this->member();
        $expected = [];
        foreach ([false, true] as $whiteLabel) {
            foreach ([['01', '01', '00'], ['02', '01', '00'], ['03', '01', '00'], ['04', '01', '00'],
                ['00', '01', '00'], ['00', '03', '00'], ['00', '06', '00'], ['00', '07', '00'],
                ['00', '05', '01'], ['00', '06', '02'], ['00', '06', '03'], ['00', '06', '05'], ['00', '06', '06']] as [$income, $outgoing, $refund]) {
                $expected[] = $this->order($member, [
                    'white_label' => $whiteLabel, 'income_code' => $income, 'outgoing_code' => $outgoing, 'refund_code' => $refund,
                ])->reference;
            }
        }
        foreach ([['00', '05', '00'], ['05', '01', '00'], ['06', '01', '00'], ['07', '01', '00'], ['08', '01', '00'], ['00', '06', '04']] as [$income, $outgoing, $refund]) {
            $this->order($member, ['income_code' => $income, 'outgoing_code' => $outgoing, 'refund_code' => $refund]);
        }
        foreach ([['provider_trade_id' => null], ['provider_trade_id' => ''], ['created_at' => now()],
            ['provider_sync_attempted_at' => now()->subSeconds(299)], ['provider_checked_at' => now()]] as $attributes) {
            $this->order($member, $attributes);
        }
        $boundary = $this->order($member, ['provider_sync_attempted_at' => now()->subSeconds(300)]);
        $expected[] = $boundary->reference;
        $this->fakeQueries();

        $this->artisan('remit:sync-orders', ['--limit' => 100])->assertSuccessful();
        $actual = $this->queriedOrders();
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame(count($expected), RemittanceOrder::whereIn('reference', $expected)->whereNotNull('provider_sync_attempted_at')->count());
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/api/whitelable/transaction/query'));
        Http::assertSentCount(count($expected) + 1);
    }

    public function test_failed_attempts_back_off_and_do_not_starve_later_orders(): void
    {
        $member = $this->member();
        $first = $this->order($member, ['created_at' => now()->subHour()]);
        $second = $this->order($member);
        $this->fakeQueries(function (array $state) use ($first): array {
            return $state['order_id'] === $first->reference ? [...$state, 'uid' => 'another-member'] : $state;
        });

        $this->artisan('remit:sync-orders', ['--limit' => 1])
            ->expectsOutput('本批选中 1 笔：同步成功 0 笔，失败 1 笔，跳过 0 笔。')->assertFailed();
        $this->assertDatabaseHas('remittance_orders', [
            'reference' => $first->reference, 'provider_checked_at' => null, 'income_code' => '03',
        ]);
        $this->assertNotNull($first->fresh()->provider_sync_attempted_at);
        $this->assertDatabaseCount('member_notifications', 0);

        $this->artisan('remit:sync-orders', ['--limit' => 1])->assertSuccessful();
        $this->assertSame([$first->reference, $second->reference], $this->queriedOrders());
        $this->artisan('remit:sync-orders')->expectsOutput('本批选中 0 笔：同步成功 0 笔，失败 0 笔，跳过 0 笔。')->assertSuccessful();
        $this->assertCount(2, $this->queriedOrders());

        $this->travel(301)->seconds();
        $this->artisan('remit:sync-orders', ['--limit' => 1])->assertFailed();
        $this->assertSame([$first->reference, $second->reference, $first->reference], $this->queriedOrders());
    }

    public function test_a_batch_continues_after_an_error_and_repeated_success_does_not_redeem_or_notify_twice(): void
    {
        $member = $this->member();
        $broken = $this->order($member, ['created_at' => now()->subHour()]);
        $paid = $this->order($member);
        $coupon = app(RemittanceCouponRepository::class)->issue((string) Str::uuid(), [
            'member_id' => $member->id, 'issue_fingerprint' => 'sync-discount', 'title' => '同步测试券',
            'discount_bps' => 3000, 'expires_at' => now()->addDay(),
        ]);
        $coupon->forceFill(['order_reference' => $paid->reference])->saveOrFail();
        config(['yaband_remit.order_sync.batch_size' => 2]);
        $this->fakeQueries(fn (array $state) => $state['order_id'] === $broken->reference
            ? [...$state, 'trade_id' => 'incorrect'] : [...$state, 'income_status_code' => '00']);

        $this->artisan('remit:sync-orders')
            ->expectsOutput('本批选中 2 笔：同步成功 1 笔，失败 1 笔，跳过 0 笔。')->assertFailed();
        $this->assertNotNull($coupon->fresh()->redeemed_at);
        $redeemedAt = $coupon->fresh()->redeemed_at;
        $this->assertDatabaseHas('member_notifications', ['order_reference' => $paid->reference, 'title' => '已确认收到汇款付款']);
        $this->travel(301)->seconds();
        $this->artisan('remit:sync-orders')->assertFailed();
        $this->assertTrue($redeemedAt->equalTo($coupon->fresh()->redeemed_at));
        $this->assertDatabaseCount('member_notifications', 1);
        $this->assertCount(4, $this->queriedOrders());
    }

    public function test_unsigned_sync_fails_before_network_and_also_backs_off(): void
    {
        $order = $this->order($this->member());
        $this->app->offsetUnset(YabandRemitSigner::class);
        $this->artisan('remit:sync-orders')->assertFailed();
        $this->artisan('remit:sync-orders')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->provider_checked_at);
        $this->assertNotNull($order->fresh()->provider_sync_attempted_at);
    }

    public function test_network_failure_preserves_finances_and_does_not_block_immediate_manual_or_callback_refresh(): void
    {
        $order = $this->order($this->member());
        Http::fake([
            'https://sync.example.test/api/auth/login' => Http::response([
                'status' => true, 'code' => 200, 'data' => [
                    'token' => 'fixture-token', 'expires_in' => 3600, 'expires_at' => now()->addHour()->timestamp, 'token_type' => 'Bearer',
                ],
            ]),
            'https://sync.example.test/api/transaction/query' => Http::failedConnection(),
        ]);
        $this->artisan('remit:sync-orders')->assertFailed();
        $this->assertNotNull($order->fresh()->provider_sync_attempted_at);
        $this->assertNull($order->fresh()->provider_checked_at);
        $this->assertSame('03', $order->fresh()->income_code);
        $this->assertDatabaseCount('member_notifications', 0);

        // 新连接场景使用独立 HTTP 工厂，避免先前失败桩仍优先匹配请求。
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeQueries(fn (array $state) => [...$state, 'income_status_code' => '00', 'outgoing_status_code' => '05']);
        $result = app(RemittanceOrderService::class)->refreshNotified($order->reference, $order->provider_trade_id);
        $this->assertTrue($result->isSuccess());
        $this->assertSame('00', $order->fresh()->income_code);
        $this->assertSame('05', $order->fresh()->outgoing_code);
        $this->assertNotNull($order->fresh()->provider_checked_at);
        $this->assertDatabaseHas('member_notifications', ['order_reference' => $order->reference, 'title' => '汇款已下发']);
        $this->artisan('remit:sync-orders')->assertSuccessful();
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_invalid_configuration_or_limit_is_rejected_before_any_attempt(): void
    {
        $order = $this->order($this->member());
        foreach (['0', '101', '-1', 'bad', '1.5', ''] as $limit) {
            $this->artisan('remit:sync-orders', ['--limit' => $limit])->assertExitCode(2);
        }
        foreach ([59, 86401, 'bad', true] as $interval) {
            config(['yaband_remit.order_sync.interval_seconds' => $interval]);
            $this->artisan('remit:sync-orders')->assertExitCode(2);
        }
        config(['yaband_remit.order_sync.interval_seconds' => 300, 'yaband_remit.order_sync.batch_size' => 101]);
        $this->artisan('remit:sync-orders')->assertExitCode(2);
        Http::assertNothingSent();
        $this->assertNull($order->fresh()->provider_sync_attempted_at);
    }

    public function test_locked_orders_are_not_queried_and_due_state_is_rechecked_under_the_order_lock(): void
    {
        $order = $this->order($this->member());
        $lock = Cache::lock('remittance-order:'.$order->reference, 40);
        $this->assertTrue($lock->get());
        try {
            $this->artisan('remit:sync-orders')->assertFailed();
        } finally {
            $lock->release();
        }
        $this->assertNull($order->fresh()->provider_sync_attempted_at);
        $order->forceFill(['provider_checked_at' => now()])->saveOrFail();
        $service = app(RemittanceOrderService::class);
        $this->assertNull($service->refreshIfDue($order->reference, now()->subSeconds(300)));
        $order->forceFill(['provider_checked_at' => null, 'income_code' => '00', 'outgoing_code' => '05'])->saveOrFail();
        $this->assertNull($service->refreshIfDue($order->reference, now()->subSeconds(300)));
        Http::assertNothingSent();
    }

    public function test_automatic_order_sync_is_disabled_by_default(): void
    {
        $commands = array_map(fn ($event) => $event->command ?? '', app(Schedule::class)->events());
        $this->assertFalse(collect($commands)->contains(fn ($command) => str_contains($command, 'remit:sync-orders')));
    }

    private function member(): User
    {
        $member = User::factory()->create();
        $members = app(YabandRemitMemberRepository::class);
        $members->saveState($members->getOrCreate($member->id), 'uid-'.$member->id, null);

        return $member;
    }

    private function order(User $member, array $attributes = []): RemittanceOrder
    {
        $reference = (string) Str::uuid();
        DB::table('remittance_orders')->insert([
            'reference' => $reference, 'provider_trade_id' => 'trade-'.$reference, 'member_id' => $member->id,
            'white_label' => false, 'principal_minor' => 10000, 'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00',
            'created_at' => now()->subMinutes(10), 'updated_at' => now()->subMinutes(10), ...$attributes,
        ]);

        return RemittanceOrder::where('reference', $reference)->firstOrFail();
    }

    private function fakeQueries(?callable $change = null): void
    {
        Http::fake(function (Request $request) use ($change) {
            if (str_ends_with($request->url(), '/api/auth/login')) {
                $data = ['token' => 'fixture-token', 'expires_in' => 3600, 'expires_at' => now()->addHour()->timestamp, 'token_type' => 'Bearer'];
            } else {
                $this->assertContains($request->url(), [
                    'https://sync.example.test/api/transaction/query', 'https://sync.example.test/api/whitelable/transaction/query',
                ]);
                $order = RemittanceOrder::where('provider_trade_id', $request['data']['trade_id'])->firstOrFail();
                $state = [
                    'order_id' => $order->reference, 'trade_id' => $order->provider_trade_id, 'mid' => 'fixture-mid',
                    'uid' => 'uid-'.$order->member_id, 'income_status_code' => $order->income_code,
                    'outgoing_status_code' => $order->outgoing_code, 'refund_status_code' => $order->refund_code,
                ];
                $data = ['transaction_info' => $change === null ? $state : $change($state)];
            }

            return Http::response(['status' => true, 'code' => 200, 'data' => $data]);
        });
    }

    private function queriedOrders(): array
    {
        return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/transaction/query'))
            ->map(fn (array $requestAndResponse) => substr($requestAndResponse[0]['data']['trade_id'], strlen('trade-')))->values()->all();
    }
}
