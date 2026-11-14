<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\RemittancePermission;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupportTicketsTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_create_and_read_their_own_tickets_and_staff_responses_record_progress(): void
    {
        Event::fake([AnonymousEvent::class]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'support']);
        $app = '/api/app/support/tickets';
        $admin = '/api/admin/support/tickets';
        $input = ['client_id' => (string) Str::uuid(), 'subject' => '汇款咨询', 'body' => '想了解到账时间'];

        $this->postJson($app, $input)->assertUnauthorized();
        $this->actingWith($member, 'app');
        $id = $this->postJson($app, $input)->assertOk()->assertJsonPath('data.version', 0)->json('data.id');
        $this->postJson($app, $input)->assertJsonPath('data.id', $id);
        $this->postJson($app, [...$input, 'body' => '修改内容'])->assertConflict();
        $this->getJson($admin)->assertForbidden();

        $this->actingWith($other, 'app');
        $this->getJson($app)->assertJsonPath('data.total', 0);
        $this->getJson($app.'/'.$id)->assertNotFound();

        $this->actingWith($staff, 'platform', [RemittancePermission::VIEW_MEMBERS]);
        $this->getJson($admin)->assertForbidden();
        $this->actingWith($staff, 'platform', [RemittancePermission::SUPPORT]);
        $this->getJson($admin.'?status=open')->assertJsonPath('data.list.0.member_id', (string) $member->id);
        $reply = ['client_id' => (string) Str::uuid(), 'version' => 0, 'status' => 'processing', 'reply' => '正在确认渠道'];
        $this->putJson($admin.'/'.$id, $reply)->assertOk()->assertJsonPath('data.updates.0.reply', $reply['reply']);
        $this->putJson($admin.'/'.$id, $reply)->assertOk()->assertJsonCount(1, 'data.updates');
        $this->putJson($admin.'/'.$id, [...$reply, 'reply' => '其他内容'])->assertConflict();
        $this->putJson($admin.'/'.$id, [...$reply, 'client_id' => (string) Str::uuid()])->assertConflict();
        $close = ['client_id' => (string) Str::uuid(), 'version' => 1, 'status' => 'resolved', 'reply' => '已说明预计时效'];
        $this->putJson($admin.'/'.$id, $close)->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->putJson($admin.'/'.$id, [...$reply, 'client_id' => (string) Str::uuid(), 'version' => 2])->assertConflict();
        $this->getJson($admin.'?status=resolved')->assertJsonPath('data.total', 1);
        $this->assertDatabaseCount('support_ticket_updates', 2);
        $this->assertDatabaseCount('member_notifications', 2);
        Event::assertDispatchedTimes(AnonymousEvent::class, 2);

        $this->actingWith($member, 'app');
        $this->getJson($app.'/'.$id)->assertOk()->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.updates.1.reply', $close['reply'])->assertJsonMissingPath('data.member_id');
        $this->getJson('/api/app/notifications')->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.list.0.support_ticket_id', $id);
    }

    private function actingWith(User $user, string $kind, array $abilities = []): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken($kind, $abilities)->plainTextToken);
    }
}
