<?php

namespace Tests\Feature;

use App\Jobs\SendMemberPush;
use App\Model\System\User;
use App\Service\Notification\MemberNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberPushTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/app/notifications/device';

    private const BRIDGE = 'https://cloud.example.test/notifications';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'member_push.enabled' => true,
            'member_push.url' => self::BRIDGE,
            'member_push.secret' => str_repeat('s', 32),
        ]);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake([self::BRIDGE => Http::response(['accepted' => true])]);
    }

    public function test_repeated_device_registration_keeps_the_encrypted_binding_and_repairs_damaged_data(): void
    {
        $this->login(User::factory()->create());
        $input = ['client_id' => 'fixture-device'];
        $this->putJson(self::PATH, $input)->assertOk();
        $first = DB::table('member_push_devices')->sole();

        $this->travel(2)->seconds();
        $this->putJson(self::PATH, $input)->assertOk();
        $unchanged = DB::table('member_push_devices')->sole();
        $this->assertSame($first->client_id, $unchanged->client_id);
        $this->assertSame($first->updated_at, $unchanged->updated_at);

        DB::table('member_push_devices')->where('id', $first->id)->update(['client_id' => 'damaged-fixture']);
        $this->putJson(self::PATH, $input)->assertOk();
        $repaired = DB::table('member_push_devices')->sole();
        $this->assertSame($first->id, $repaired->id);
        $this->assertSame($input['client_id'], decrypt($repaired->client_id, false));
    }

    public function test_push_only_queues_once_and_only_exposes_a_notice_identifier(): void
    {
        $member = User::factory()->create();
        $this->getJson('/api/app/notifications')->assertUnauthorized();
        $this->login($member);
        $this->putJson(self::PATH, ['client_id' => 'invalid id'])->assertUnprocessable();
        $this->putJson(self::PATH, ['client_id' => 'fixture-device'])->assertOk();
        $this->putJson(self::PATH, ['client_id' => 'fixture-device'])->assertOk();
        $this->assertDatabaseCount('member_push_devices', 1);
        $this->assertNotSame('fixture-device', DB::table('member_push_devices')->value('client_id'));

        $input = ['request_id' => (string) Str::uuid(), 'title' => '个人通知', 'body' => '敏感资料'];
        $notices = app(MemberNotificationService::class);
        $notice = $notices->send($member->id, null, $input, broadcast: false)->getData();
        $notices->send($member->id, null, $input, broadcast: false);
        DB::beginTransaction();
        $notices->send($member->id, null, [...$input, 'request_id' => (string) Str::uuid()], broadcast: false);
        DB::rollBack();
        Queue::assertPushed(SendMemberPush::class, 1);
        $job = Queue::pushed(SendMemberPush::class)->first();
        app()->call([$job, 'handle']);
        Http::assertSent(fn (Request $request) => $request->url() === self::BRIDGE
            && $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 32))
            && $request['cid'] === 'fixture-device' && $request['notice_id'] === $notice['reference']
            && array_keys($request->data()) === ['cid', 'notice_id', 'request_id']);

        $path = '/api/app/notifications/'.$notice['reference'];
        $this->getJson($path)->assertJsonPath('data.body', '敏感资料');
        $this->login(User::factory()->create());
        $this->getJson($path)->assertNotFound();
    }

    public function test_a_queued_notification_cannot_go_to_a_reassigned_logged_out_or_expired_device(): void
    {
        $former = User::factory()->create();
        $current = User::factory()->create();
        $this->login($former);
        $this->putJson(self::PATH, ['client_id' => 'shared-device'])->assertOk();
        $this->sendNotice($former);
        $old = Queue::pushed(SendMemberPush::class)->first();
        $this->login($current);
        $this->putJson(self::PATH, ['client_id' => 'shared-device'])->assertOk();
        app()->call([$old, 'handle']);
        $this->sendNotice($current);
        $currentJob = Queue::pushed(SendMemberPush::class)->last();
        $this->postJson('/api/app/auth/logout')->assertOk();
        $this->assertDatabaseCount('member_push_devices', 0);
        app()->call([$currentJob, 'handle']);

        $this->login($current, now()->addMinute());
        $this->putJson(self::PATH, ['client_id' => 'shared-device'])->assertOk();
        $this->sendNotice($current);
        $expiring = Queue::pushed(SendMemberPush::class)->last();
        $this->travel(2)->minutes();
        app()->call([$expiring, 'handle']);
        Http::assertNothingSent();
    }

    public function test_notifications_still_save_when_push_is_disabled(): void
    {
        config(['member_push.enabled' => false]);
        $member = User::factory()->create();
        $this->login($member);
        $this->putJson(self::PATH, ['client_id' => 'fixture-device'])->assertServiceUnavailable();
        $this->sendNotice($member);
        $this->getJson('/api/app/notifications/unread')->assertJsonPath('data.unread_count', 1);
        Queue::assertNothingPushed();
    }

    private function login(User $member, ?\DateTimeInterface $expires = null): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('app', [], $expires)->plainTextToken);
    }

    private function sendNotice(User $member): void
    {
        app(MemberNotificationService::class)->send($member->id, null, [
            'request_id' => (string) Str::uuid(), 'title' => '新消息', 'body' => '站内消息',
        ], broadcast: false);
    }
}
