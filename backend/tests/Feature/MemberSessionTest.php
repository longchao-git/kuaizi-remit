<?php

namespace Tests\Feature;

use App\Model\System\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MemberSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_code_signs_into_the_same_local_account_and_is_blocked_in_production(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_sms_login' => true, 'remit.sms.test_autofill' => true]);
        $mobile = ['mobile' => '+34600000000'];

        $this->postJson('/api/app/auth/sms-code', ['mobile' => '+33600000000'])->assertUnprocessable();
        $code = $this->postJson('/api/app/auth/sms-code', $mobile)
            ->assertJsonPath('data.sent', true)
            ->json('data.test_code');
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);

        $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => '000000'])->assertJsonPath('code', 1001);
        $this->assertDatabaseCount('users', 0);

        $first = $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => $code])->assertJsonPath('code', 0);

        // 验证码单次消费，重复使用失败。
        $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => $code])->assertJsonPath('code', 1001);

        $this->travel(61)->seconds();
        $nextCode = $this->postJson('/api/app/auth/sms-code', $mobile)->json('data.test_code');
        $second = $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => $nextCode])
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.member', $first->json('data.member'));

        $savedMember = User::query()->sole();
        $this->assertSame($mobile['mobile'], $savedMember->phone_e164);
        $this->assertStringNotContainsString($mobile['mobile'], $savedMember->getRawOriginal('phone_cipher'));
        $this->assertArrayNotHasKey('phone_cipher', $savedMember->toArray());
        $this->assertArrayNotHasKey('phone_lookup', $savedMember->toArray());
        $this->assertDatabaseCount('users', 1);
        $this->withToken($second->json('data.token'))->getJson('/api/app/auth/current')
            ->assertJsonPath('data', $first->json('data.member'));
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/app/auth/logout')->assertJsonPath('data', true);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/auth/current')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        // 先让发送限流窗口过期，生产环境才能命中 404 门禁。
        $this->travel(61)->seconds();
        $this->app->instance('env', 'production');
        $this->postJson('/api/app/auth/sms-code', $mobile)
            ->assertNotFound()->assertJsonPath('msg', '短信登录暂未开放');
        $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => $nextCode])
            ->assertNotFound()->assertJsonPath('msg', '短信登录暂未开放');
        $this->withToken($first->json('data.token'))->getJson('/api/app/auth/current')->assertUnauthorized();
    }

    public function test_sms_login_is_disabled_by_default_and_cannot_sign_in_staff(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_sms_login' => false]);
        $input = ['mobile' => '+34600000000', 'code' => '123456'];
        $this->postJson('/api/app/auth/sms-login', $input)->assertNotFound();

        config(['remit.local_sms_login' => true, 'remit.sms.test_autofill' => true]);
        $code = $this->postJson('/api/app/auth/sms-code', ['mobile' => $input['mobile']])
            ->assertJsonPath('data.sent', true)
            ->json('data.test_code');
        User::factory()->create(['phone_e164' => $input['mobile'], 'is_platform_staff' => true]);
        $this->postJson('/api/app/auth/sms-login', ['mobile' => $input['mobile'], 'code' => $code])
            ->assertJsonPath('code', 1001);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_local_account_can_sign_in_and_restore_its_session(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_login_email' => 'qa@example.test']);
        $member = User::factory()->create(['email' => 'qa@example.test', 'password' => 'local-test-password']);
        $other = User::factory()->create(['password' => 'local-test-password']);

        $this->postJson('/api/app/auth/local-login', ['email' => $other->email, 'password' => 'local-test-password'])
            ->assertJsonPath('code', 1001);
        $this->postJson('/api/app/auth/local-login', ['email' => $member->email, 'password' => 'incorrect'])
            ->assertJsonPath('code', 1001);
        $response = $this->postJson('/api/app/auth/local-login', ['email' => $member->email, 'password' => 'local-test-password'])
            ->assertJsonPath('code', 0)->assertJsonPath('data.member.memberId', (string) $member->id);

        $this->withToken($response->json('data.token'))->getJson('/api/app/auth/current')
            ->assertOk()->assertExactJson(['code' => 0, 'msg' => '操作成功', 'data' => [
                'memberId' => (string) $member->id,
                'userName' => $member->name,
            ]]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/admin/remittance/rules')->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->app->instance('env', 'production');
        $this->getJson('/api/app/auth/current')->assertUnauthorized();
        $this->postJson('/api/app/auth/local-login', ['email' => $member->email, 'password' => 'local-test-password'])
            ->assertNotFound();
    }

    public function test_staff_cannot_sign_in_with_the_local_member_endpoint(): void
    {
        $this->app->instance('env', 'local');
        $staff = User::factory()->create(['is_platform_staff' => true, 'password' => 'local-test-password']);
        config(['remit.local_login_email' => $staff->email]);

        $this->postJson('/api/app/auth/local-login', ['email' => $staff->email, 'password' => 'local-test-password'])
            ->assertJsonPath('code', 1001);
        $this->withToken($staff->createToken('platform')->plainTextToken)->getJson('/api/app/auth/current')
            ->assertForbidden();
    }

    public function test_logout_revokes_only_the_current_member_token(): void
    {
        $member = User::factory()->create();
        $current = $member->createToken('phone');
        $other = $member->createToken('tablet');

        $this->withToken($current->plainTextToken)->postJson('/api/app/auth/logout')
            ->assertOk()->assertJsonPath('data', true);
        $this->assertModelMissing($current->accessToken);
        $this->assertModelExists($other->accessToken);

        $this->app['auth']->forgetGuards();
        $this->get('/api/app/support/messages')->assertUnauthorized()->assertJsonPath('code', 401);
        $this->app['auth']->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/app/support/messages')->assertOk();
    }

    public function test_authenticated_reads_record_token_use_once_per_minute(): void
    {
        $this->travelTo(now()->startOfSecond());
        $token = User::factory()->create()->createToken('phone');
        $this->withToken($token->plainTextToken);
        $writes = 0;
        DB::listen(function ($query) use (&$writes): void {
            if (str_starts_with($query->sql, 'update "personal_access_tokens"')) {
                $writes++;
            }
        });

        foreach (['/app/auth/current', '/app/remittance/announcement', '/app/notifications/unread', '/app/remittance/orders'] as $path) {
            $this->app['auth']->forgetGuards();
            $this->getJson($path)->assertOk();
            $this->travel(1)->second();
        }
        $firstUse = now()->subSeconds(4)->timestamp;
        $this->assertSame(1, $writes);
        $this->assertSame($firstUse, $token->accessToken->fresh()->last_used_at->timestamp);

        $this->travelTo(now()->setTimestamp($firstUse + 60));
        $this->app['auth']->forgetGuards();
        $this->getJson('/app/auth/current')->assertOk();
        $this->assertSame(2, $writes);
        $this->assertSame(now()->timestamp, $token->accessToken->fresh()->last_used_at->timestamp);
    }

    public function test_platform_token_cannot_be_revoked_by_member_logout(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $token = $staff->createToken('platform');

        $this->withToken($token->plainTextToken)->postJson('/api/app/auth/logout')->assertForbidden();
        $this->assertModelExists($token->accessToken);
    }
}
