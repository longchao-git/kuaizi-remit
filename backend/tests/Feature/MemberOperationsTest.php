<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\RemittancePermission;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MemberOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_member_search_requires_permission_and_excludes_staff(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'name' => 'Case Operator']);
        $member = User::factory()->create(['name' => 'Case Member']);
        User::factory()->create(['name' => 'Other '.$member->id]);
        $url = '/api/admin/remittance/members';

        $this->getJson($url)->assertUnauthorized();
        $this->asToken($member, 'app', [RemittancePermission::VIEW_MEMBERS])->getJson($url)->assertForbidden();
        $this->asToken($staff, 'platform', [RemittancePermission::SUPPORT])->getJson($url)->assertForbidden();
        $this->asToken($staff, 'platform', [RemittancePermission::VIEW_MEMBERS])->getJson($url.'?keyword=CASE')
            ->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.id', (string) $member->id)
            ->assertJsonMissingPath('data.list.0.password');
        $this->getJson($url.'?keyword=%25')->assertJsonPath('data.total', 0);
        $this->getJson($url.'?keyword='.$member->id)->assertJsonPath('data.list.0.id', (string) $member->id);
    }

    public function test_notification_retries_reads_and_account_isolation(): void
    {
        Event::fake([AnonymousEvent::class]);
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $path = '/api/admin/remittance/members/'.$member->id.'/notifications';
        $input = ['request_id' => (string) Str::uuid(), 'title' => '通知', 'body' => '请查看站内消息'];

        $this->asToken($staff, 'platform', [RemittancePermission::VIEW_MEMBERS])->postJson($path, $input)->assertForbidden();
        $this->asToken($staff, 'platform', [RemittancePermission::SEND_NOTIFICATION]);
        $id = $this->postJson($path, $input)->assertOk()->json('data.id');
        $this->postJson($path, $input)->assertJsonPath('data.id', $id);
        $this->postJson($path, [...$input, 'body' => '不同内容'])->assertConflict();
        Event::assertDispatchedTimes(AnonymousEvent::class, 1);
        $newId = $this->postJson($path, [...$input, 'request_id' => (string) Str::uuid()])->assertOk()->json('data.id');
        $this->assertDatabaseCount('member_notifications', 2);
        $this->getJson('/api/app/notifications')->assertForbidden();

        $this->asToken($other, 'app')->getJson('/api/app/notifications')->assertJsonPath('data.total', 0);
        $this->getJson('/api/app/notifications/unread')->assertJsonPath('data.unread_count', 0);
        $this->postJson('/api/app/notifications/'.$id.'/read')->assertNotFound();
        $this->asToken($member, 'app')->getJson('/api/app/notifications')->assertJsonPath('data.unread_count', 2)
            ->assertJsonPath('data.list.0.id', $newId)->assertJsonMissingPath('data.list.0.sender_id');
        $this->postJson('/api/app/notifications/'.$id.'/read', ['through' => true])->assertJsonPath('data.unread_count', 1);
        $this->postJson('/api/app/notifications/'.$id.'/read')->assertJsonPath('data.unread_count', 1);
        $this->postJson('/api/app/notifications/'.$newId.'/read', ['through' => true])->assertJsonPath('data.unread_count', 0);
        Event::assertDispatchedTimes(AnonymousEvent::class, 4);
        $this->getJson('/api/app/notifications/unread')->assertJsonPath('data.unread_count', 0);
        $this->asToken($member, 'app')->getJson('/api/app/notifications')->assertJsonPath('data.unread_count', 0)
            ->assertJsonPath('data.total', 2);
    }

    public function test_member_contacts_hide_generated_emails_and_preserve_real_contacts(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $testEmail = 'test-sms-'.str_repeat('a', 64).'@members.invalid';
        $contacts = [
            ['phone-user@mobile.invalid', null],
            [$testEmail, null],
            ['contact@example.com', 'contact@example.com'],
            ['contact@members.invalid', 'contact@members.invalid'],
        ];

        $this->asToken($staff, 'platform', [RemittancePermission::VIEW_MEMBERS]);
        foreach ($contacts as $index => [$email, $visibleEmail]) {
            $member = User::factory()->create(['email' => $email, 'phone_e164' => '+3460000000'.$index]);
            $this->getJson('/api/admin/remittance/members?keyword='.$member->id)
                ->assertOk()
                ->assertJsonPath('data.list.0.id', (string) $member->id)
                ->assertJsonPath('data.list.0.email', $visibleEmail)
                ->assertJsonPath('data.list.0.masked_phone', '+34 ••••• 000'.$index);
            $this->getJson('/api/admin/remittance/members?keyword='.rawurlencode('+3460000000'.$index))
                ->assertOk()->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.list.0.id', (string) $member->id)
                ->assertJsonPath('data.list.0.masked_phone', '+34 ••••• 000'.$index);
            $this->assertSame($email, $member->fresh()->email);
        }
    }

    private function asToken(User $user, string $type, array $permissions = []): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($user->createToken($type, $permissions)->plainTextToken);
    }
}
