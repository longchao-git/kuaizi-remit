<?php

namespace Tests\Feature;

use App\Model\Support\SupportMessage;
use App\Model\System\User;
use App\Permission\RemittancePermission;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_cursors_are_independent_monotonic_and_reject_foreign_messages(): void
    {
        Event::fake([AnonymousEvent::class]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $memberToken = $member->createToken('app')->plainTextToken;
        $staffToken = $staff->createToken('platform', [RemittancePermission::SUPPORT])->plainTextToken;
        $messages = '/api/app/support/messages';
        $status = '/api/app/support/status';
        $staffPath = '/api/admin/support/members/'.$member->id;
        $conversations = '/api/admin/support/conversations?unread_only=1';

        $first = $this->withToken($memberToken)->postJson($messages, $this->draft('第一条'))->json('data.id');
        $second = $this->postJson($messages, $this->draft('第二条'))->json('data.id');
        $this->getJson($conversations)->assertForbidden();
        $this->getJson($status)->assertJsonPath('data.unread_count', 0);
        $this->app['auth']->forgetGuards();
        $foreign = $this->withToken($other->createToken('app')->plainTextToken)
            ->postJson($messages, $this->draft('另一会话'))->json('data.id');
        $this->postJson('/api/app/support/read', ['message_id' => $second])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($staffToken)->getJson($conversations)->assertOk()->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.member_id', (string) $other->id)->assertJsonPath('data.list.1.unread_count', 2);
        $this->postJson($staffPath.'/read', ['message_id' => $foreign])->assertNotFound();
        $this->getJson($staffPath.'/messages')->assertOk();
        $this->getJson($conversations)->assertJsonPath('data.list.1.unread_count', 2);
        $this->postJson($staffPath.'/read', ['message_id' => $first])->assertJsonPath('data.unread_count', 1);
        $this->postJson($staffPath.'/read', ['message_id' => $second])->assertJsonPath('data.unread_count', 0);
        $this->postJson($staffPath.'/read', ['message_id' => $first])->assertJsonPath('data.unread_count', 0);
        $this->getJson($conversations)->assertJsonPath('data.total', 1);
        $this->getJson('/api/admin/support/conversations')->assertJsonPath('data.total', 2);
        $reply = $this->postJson($staffPath.'/messages', $this->draft('客服回复'))->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->withToken($memberToken)->getJson($status)->assertJsonPath('data.unread_count', 1);
        $this->postJson('/api/app/support/read', ['message_id' => $reply])->assertJsonPath('data.unread_count', 0);
        $this->postJson('/api/app/support/read', ['message_id' => $second])->assertJsonPath('data.unread_count', 0);
        $this->getJson($status)->assertJsonPath('data.unread_count', 0);
    }

    public function test_a_reconnecting_member_can_retrieve_more_than_fifty_missed_messages_in_order(): void
    {
        $member = User::factory()->create();
        $url = '/api/app/support/messages';
        $token = $member->createToken('app', ['app:session'])->plainTextToken;
        $this->withToken($token)->getJson($url)->assertJsonPath('data.next_after_id', null);

        $ids = [];
        for ($index = 0; $index < 53; $index++) {
            $ids[] = SupportMessage::query()->create([
                'member_id' => $member->id,
                'sender_id' => $member->id,
                'client_id' => (string) Str::uuid(),
                'body' => '消息 '.$index,
            ])->id;
        }

        $firstPage = $this->getJson($url.'?after_id=0')->assertOk()
            ->assertJsonPath('data.has_more', true)->assertJsonPath('data.next_after_id', $ids[49]);
        $secondPage = $this->getJson($url.'?after_id='.$ids[49])->assertOk()
            ->assertJsonPath('data.has_more', false)->assertJsonPath('data.next_after_id', $ids[52]);
        $this->assertSame($ids, array_column([...$firstPage->json('data.list'), ...$secondPage->json('data.list')], 'id'));
    }

    public function test_retries_persist_one_message_and_history_filters_by_member_and_cursor(): void
    {
        Event::fake([AnonymousEvent::class]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $token = $member->createToken('app', ['app:session'])->plainTextToken;
        $payload = ['client_id' => (string) Str::uuid(), 'body' => '查询汇款进度'];
        $url = '/api/app/support/messages';

        $id = $this->withToken($token)->postJson($url, $payload)->assertJsonPath('code', 0)->json('data.id');
        $this->postJson($url, $payload)->assertJsonPath('data.id', $id);
        $this->postJson($url, [...$payload, 'body' => '不同内容'])->assertJsonPath('code', 409);
        $this->assertDatabaseCount('support_messages', 1);
        $this->getJson($url.'?after_id='.$id)->assertJsonPath('data.list', []);
        $this->getJson('/api/admin/support/members/'.$other->id.'/messages')->assertForbidden();

        $this->app['auth']->forgetGuards();
        $staffToken = $staff->createToken('platform', [RemittancePermission::SUPPORT])->plainTextToken;
        $staffUrl = '/api/admin/support/members/'.$member->id.'/messages';
        $this->withToken($staffToken)->getJson($staffUrl)->assertJsonPath('data.list.0.body', $payload['body']);
        $this->postJson($staffUrl, ['client_id' => (string) Str::uuid(), 'body' => '正在核对'])
            ->assertJsonPath('data.sender', 'staff');

        $this->app['auth']->forgetGuards();
        $otherToken = $other->createToken('app', ['app:session'])->plainTextToken;
        $this->withToken($otherToken)->getJson($url)->assertJsonPath('data.list', []);
    }

    private function draft(string $body): array
    {
        return ['client_id' => (string) Str::uuid(), 'body' => $body];
    }
}
