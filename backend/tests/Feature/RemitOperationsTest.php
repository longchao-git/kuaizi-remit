<?php

namespace Tests\Feature;

use App\Jobs\SendMemberPush;
use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Service\Remittance\Yaband\YabandKycStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RemitOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const MEMBER_CLOSURES = '/api/app/account/closures';

    private const ADMIN = '/api/admin/remittance';

    public function test_member_closure_is_idempotent_private_and_cannot_be_approved_with_unsettled_orders(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->order($member, '02', '01', '00');

        $this->postJson(self::MEMBER_CLOSURES)->assertUnauthorized();
        $this->loginAs($member);
        $pending = $this->postJson(self::MEMBER_CLOSURES, ['reason' => '不再使用'])->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.member_id')->json('data.id');
        $this->postJson(self::MEMBER_CLOSURES)->assertOk()->assertJsonPath('data.id', $pending);
        $this->assertDatabaseCount('member_closure_requests', 1);
        $this->getJson(self::ADMIN.'/closures')->assertForbidden();

        $this->loginAs($other);
        $this->getJson(self::MEMBER_CLOSURES)->assertOk()->assertJsonPath('data.total', 0);

        $this->loginAs($this->staff('support'));
        $this->getJson(self::ADMIN.'/closures')->assertForbidden();

        $operator = $this->staff();
        $this->loginAs($operator);
        $review = self::ADMIN.'/closures/'.$pending;
        $this->getJson(self::ADMIN.'/closures?status=pending&member_id='.$member->id)
            ->assertOk()->assertJsonPath('data.list.0.reason', '不再使用');
        $this->putJson($review, ['decision' => 'approved', 'note' => '资金已结清'])->assertConflict();
        $this->putJson($review, ['decision' => 'rejected', 'note' => '请先处理订单'])->assertOk()
            ->assertJsonPath('data.reviewed_by', (string) $operator->id)->assertJsonPath('data.status', 'rejected');
        $this->putJson($review, ['decision' => 'approved', 'note' => '重复审核'])->assertConflict();

        $this->loginAs($member);
        $this->getJson(self::MEMBER_CLOSURES)->assertJsonPath('data.list.0.review_note', '请先处理订单')
            ->assertJsonMissingPath('data.list.0.reviewed_by');
        $next = $this->postJson(self::MEMBER_CLOSURES)->assertOk()->json('data.id');
        $this->assertNotSame($pending, $next);
        DB::table('remittance_orders')->where('reference', $order)->update(['income_code' => '00', 'refund_code' => '04']);
        $this->loginAs($operator);
        $this->putJson(self::ADMIN.'/closures/'.$next, ['decision' => 'approved', 'note' => '等待执行'])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->loginAs($member);
        $this->postJson(self::MEMBER_CLOSURES)->assertOk()->assertJsonPath('data.id', $next);
        $this->getJson(self::MEMBER_CLOSURES)->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_dashboard_uses_madrid_order_creation_dates_amounts_and_refund_priority(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(12, 0));
        $member = User::factory()->create();
        $out = $this->order($member, '00', '05', '00', '2026-11-13 23:00:00');
        $this->order($member, '00', '05', '04', '2026-11-14 10:00:00');
        $this->order($member, '02', '01', '00', '2026-11-14 11:00:00');
        $this->order($member, '00', '05', '00', '2026-11-13 22:59:59');
        $this->order($member, '00', '05', '00', '2026-10-01 10:00:00');

        $this->loginAs($member);
        $this->getJson(self::ADMIN.'/dashboard')->assertForbidden();
        $this->loginAs($this->staff('read_only'));
        $this->getJson('/api/user/menus')->assertJsonFragment(['component' => 'remittance/dashboard'])
            ->assertJsonMissing(['component' => 'remittance/push']);
        $this->getJson(self::ADMIN.'/dashboard?days=1')->assertOk()
            ->assertJsonPath('data.start_date', '2026-11-14')
            ->assertJsonPath('data.metrics', [
                'order_count' => 3, 'paid_count' => 2, 'paid_principal_minor' => 20000, 'paid_out_count' => 1, 'refunded_count' => 1,
            ])
            ->assertJsonPath('data.trend', [
                ['date' => '2026-11-14', 'order_count' => 3, 'paid_out_count' => 1],
            ])
            ->assertJsonPath('data.recent.1.status_label', '已退款')
            ->assertJsonPath('data.recent.2.reference', $out);
        $this->getJson(self::ADMIN.'/dashboard?days=7')->assertOk()->assertJsonPath('data.metrics.order_count', 4)
            ->assertJsonCount(7, 'data.trend')
            ->assertJsonPath('data.trend.0', ['date' => '2026-11-08', 'order_count' => 0, 'paid_out_count' => 0])
            ->assertJsonPath('data.trend.5', ['date' => '2026-11-13', 'order_count' => 1, 'paid_out_count' => 1]);
        $this->getJson(self::ADMIN.'/dashboard?days=90')->assertUnprocessable();
    }

    public function test_dashboard_counts_each_exception_once_during_madrid_clock_change(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 10, 25)->setTime(12, 0));
        $member = User::factory()->create();
        $this->order($member, '05', '01', '00', '2026-10-24 22:00:00');
        $this->order($member, '00', '06', '00', '2026-10-25 00:30:00');
        $this->order($member, '00', '06', '05', '2026-10-25 01:30:00');
        $this->order($member, '00', '05', '00', '2026-10-25 22:59:59');
        $this->order($member, '00', '05', '00', '2026-10-25 23:00:00');

        $this->loginAs($this->staff('read_only'));
        $this->getJson(self::ADMIN.'/dashboard?days=1')->assertOk()
            ->assertJsonPath('data.trend.0', ['date' => '2026-10-25', 'order_count' => 4, 'paid_out_count' => 1])
            ->assertJsonPath('data.exceptions', [
                'payment_failed_count' => 1, 'payout_failed_count' => 1, 'refund_failed_count' => 1,
            ]);
    }

    public function test_staff_order_filters_and_read_only_details_use_saved_states_and_channels(): void
    {
        $member = User::factory()->create();
        $refundFailed = $this->order($member, '00', '05', '05');
        $refunded = $this->order($member, '00', '05', '04');
        $pending = $this->order($member, '02', '01', '00');
        $bank = $this->order($member, '00', '07', '00');
        $other = $this->order(User::factory()->create(), '00', '05', '05');
        DB::table('remittance_orders')->whereIn('reference', [$refundFailed, $refunded, $pending, $other])->update([
            'quote_snapshot' => json_encode(['payment_method' => 'sepa', 'payout_method' => 'wechat', 'payout_minor' => 79999], JSON_THROW_ON_ERROR),
        ]);
        DB::table('remittance_orders')->where('reference', $bank)->update([
            'quote_snapshot' => json_encode(['payment_method' => 'card', 'payout_method' => 'bank'], JSON_THROW_ON_ERROR),
        ]);
        DB::table('remittance_orders')->where('reference', $pending)->update(['payment_url' => 'https://test.example/payment']);

        $url = self::ADMIN.'/orders';
        $this->loginAs($member);
        $this->getJson($url.'/'.$pending)->assertForbidden();
        $this->loginAs($this->staff('read_only'));
        $this->getJson($url.'?member_id='.$member->id.'&payment_method=sepa&payout_method=wechat&status=exception')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.reference', $refundFailed)
            ->assertJsonPath('data.summary.total', 3)->assertJsonPath('data.summary.exception', 1)
            ->assertJsonPath('data.summary.pending_payment', 1)->assertJsonPath('data.summary.refunded', 1)
            ->assertJsonPath('data.summary.paid_out', 0);
        $this->getJson($url.'?payment_method=card&payout_method=bank&status=exception')
            ->assertOk()->assertJsonPath('data.list.0.reference', $bank);
        $this->getJson($url.'?status=invalid')->assertUnprocessable();
        $this->getJson($url.'/'.Str::uuid())->assertNotFound();
        $this->getJson($url.'/'.$pending)->assertOk()->assertJsonPath('data.quote.payout_minor', 79999)
            ->assertJsonPath('data.payment_method', 'sepa')->assertJsonPath('data.payout_method', 'wechat')
            ->assertJsonMissingPath('data.payment_url');
        $this->postJson($url.'/'.$pending.'/refresh')->assertForbidden();
    }

    public function test_due_reminders_only_send_once_to_eligible_members_and_record_disabled_push(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 7, 14)->setTime(16, 29));
        config(['member_push.enabled' => false]);
        $eligible = $this->candidate();
        $late = $this->candidate('5001');
        $this->candidate('5003');
        $withOrder = $this->candidate();
        $this->order($withOrder, '02', '01', '00');
        $closing = $this->candidate();
        $this->loginAs($closing);
        $this->postJson(self::MEMBER_CLOSURES)->assertOk();
        $this->getJson(self::ADMIN.'/push/plans')->assertForbidden();

        $operator = $this->staff();
        $this->loginAs($operator);
        $path = self::ADMIN.'/push/plans';
        $input = $this->plan();
        $this->postJson($path, [...$input, 'timezone' => 'invalid'])->assertUnprocessable();
        $id = $this->postJson($path, $input)->assertOk()->assertJsonPath('data.status', 'draft')->json('data.id');
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->putJson($path.'/'.$id.'/status', ['active' => true])->assertOk();
        $this->putJson($path.'/'.$id, $input)->assertConflict();
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->assertDatabaseCount('push_reminder_sends', 0);

        $this->travel(1)->minutes();
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->assertDatabaseCount('member_notifications', 1);
        $this->getJson(self::ADMIN.'/push/records?plan_id='.$id)->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.member_id', (string) $eligible->id)
            ->assertJsonPath('data.list.0.push_status', 'disabled');
        $this->loginAs($eligible);
        $this->getJson('/api/app/notifications')->assertJsonPath('data.list.0.title', $input['title']);

        $this->loginAs($operator);
        $this->putJson($path.'/'.$id.'/status', ['active' => false])->assertOk()->assertJsonPath('data.status', 'paused');
        $this->putJson($path.'/'.$id, [...$input, 'name' => '修改后的计划'])->assertOk();
        $this->travel(2)->minutes();
        $this->putJson($path.'/'.$id.'/status', ['active' => true])->assertOk();
        DB::table('yaband_remit_members')->where('member_id', $late->id)->update(['statuses' => $this->statuses('5004')]);
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->assertDatabaseCount('push_reminder_sends', 1);
        $this->travelTo(now('UTC')->addDay()->setTime(16, 29));
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->assertDatabaseCount('push_reminder_sends', 1);
        $this->travel(1)->minutes();
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->assertDatabaseCount('push_reminder_sends', 2);
        $this->getJson(self::ADMIN.'/push/records?plan_id='.$id)->assertOk()
            ->assertJsonPath('data.list.0.plan_name', '修改后的计划')
            ->assertJsonPath('data.list.1.plan_name', $input['name']);
    }

    public function test_reminder_records_distinguish_no_device_gateway_acceptance_skip_and_final_failure(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(17, 29));
        $bridge = 'https://push.example.test/bridge';
        config(['member_push.enabled' => true, 'member_push.url' => $bridge, 'member_push.secret' => str_repeat('s', 32)]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([$bridge => Http::sequence()->push(['accepted' => true])->push([], 502)]);

        $noDevice = $this->candidate();
        $member = $this->candidate();
        foreach (['device-accepted', 'device-failed', 'device-skipped'] as $cid) {
            $this->loginAs($member);
            $this->putJson('/api/app/notifications/device', ['client_id' => $cid])->assertOk();
        }
        $operator = $this->staff();
        $this->loginAs($operator);
        $path = self::ADMIN.'/push/plans';
        $id = $this->postJson($path, $this->plan())->assertOk()->json('data.id');
        $this->putJson($path.'/'.$id.'/status', ['active' => true])->assertOk();
        $this->travel(1)->minutes();
        $this->artisan('remit:send-due-reminders')->assertSuccessful();

        Queue::assertPushed(SendMemberPush::class, 3);
        [$accepted, $failed, $skipped] = Queue::pushed(SendMemberPush::class)->all();
        app()->call([$accepted, 'handle']);
        try {
            app()->call([$failed, 'handle']);
            $this->fail('Expected the bridge to reject the push');
        } catch (\RuntimeException $exception) {
            $this->assertSame('UniPush 转发服务未接受通知。', $exception->getMessage());
        }
        $failed->failed(null);
        DB::table('member_push_devices')->where('id', $skipped->device)->delete();
        app()->call([$skipped, 'handle']);
        Http::assertSentCount(2);

        $this->getJson(self::ADMIN.'/push/records')->assertOk()
            ->assertJsonPath('data.list.0.member_id', (string) $member->id)
            ->assertJsonPath('data.list.0.deliveries.accepted', 1)
            ->assertJsonPath('data.list.0.deliveries.failed', 1)
            ->assertJsonPath('data.list.0.deliveries.skipped', 1)
            ->assertJsonPath('data.list.1.member_id', (string) $noDevice->id)
            ->assertJsonPath('data.list.1.push_status', 'no_device');
    }

    public function test_late_night_reminder_can_catch_up_after_local_midnight(): void
    {
        $this->travelTo(now('UTC')->setDate(2026, 11, 14)->setTime(22, 49));
        $this->candidate();
        $this->loginAs($this->staff());
        $path = self::ADMIN.'/push/plans';
        $id = $this->postJson($path, [...$this->plan(), 'send_time' => '23:50'])->assertOk()->json('data.id');
        $this->putJson($path.'/'.$id.'/status', ['active' => true])->assertOk();
        $this->travel(21)->minutes();
        $this->artisan('remit:send-due-reminders')->assertSuccessful();
        $this->assertDatabaseCount('push_reminder_sends', 1);
    }

    private function order(User $member, string $income, string $outgoing, string $refund, ?string $created = null): string
    {
        $reference = (string) Str::uuid();
        DB::table('remittance_orders')->insert([
            'reference' => $reference, 'member_id' => $member->id, 'white_label' => false,
            'principal_minor' => 10000, 'income_code' => $income, 'outgoing_code' => $outgoing, 'refund_code' => $refund,
            'created_at' => $created ?? now(), 'updated_at' => now(),
        ]);

        return $reference;
    }

    private function loginAs(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $name = $user->is_platform_staff ? 'platform' : 'member';
        $this->withToken($user->createToken($name, PlatformRoles::abilities($user))->plainTextToken);
    }

    private function staff(string $role = 'operations'): User
    {
        return User::factory()->create(['is_platform_staff' => true, 'platform_role' => $role]);
    }

    private function candidate(string $kyc = '5004'): User
    {
        $member = User::factory()->create();
        DB::table('yaband_remit_members')->insert([
            'member_id' => $member->id, 'create_request_id' => (string) Str::uuid(), 'uid' => 'uid-'.$member->id,
            'statuses' => $this->statuses($kyc), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $member;
    }

    private function statuses(string $kyc): string
    {
        return json_encode(YabandKycStatus::fromQuery(['kyc_status_code' => $kyc, 'user_status_code' => '1001', 'remit_status_code' => '4001']), JSON_THROW_ON_ERROR);
    }

    private function plan(): array
    {
        return [
            'name' => '首单汇款提醒', 'title' => '查看首单优惠', 'body' => '打开筷子闪汇查看您的首单优惠。',
            'send_time' => '18:30', 'timezone' => 'Europe/Madrid',
        ];
    }
}
