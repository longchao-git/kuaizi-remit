<?php

namespace Tests\Feature;

use App\Model\System\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_fixed_code_signs_into_the_same_local_account_and_is_blocked_in_production(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_sms_login' => true]);
        $mobile = ['mobile' => '+34600000000'];
        $this->postJson('/api/app/auth/sms-code', ['mobile' => '+33600000000'])->assertUnprocessable();
        $this->postJson('/api/app/auth/sms-code', $mobile)->assertJsonPath('data', true);
        $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => '000000'])->assertJsonPath('code', 1001);
        $this->assertDatabaseCount('users', 0);
        $first = $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => '123456'])->assertJsonPath('code', 0);
        $second = $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => '123456'])
            ->assertJsonPath('data.member', $first->json('data.member'));
        $this->assertDatabaseHas('users', ['phone_e164' => $mobile['mobile']]);
        $this->assertDatabaseCount('users', 1);
        $this->withToken($second->json('data.token'))->getJson('/api/app/auth/current')
            ->assertJsonPath('data', $first->json('data.member'));
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/app/auth/logout')->assertJsonPath('data', true);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/app/auth/current')->assertUnauthorized();
        $this->app['auth']->forgetGuards();

        $this->app->instance('env', 'production');
        $this->postJson('/api/app/auth/sms-code', $mobile)->assertNotFound();
        $this->postJson('/api/app/auth/sms-login', $mobile + ['code' => '123456'])->assertNotFound();
        $this->withToken($first->json('data.token'))->getJson('/api/app/auth/current')->assertUnauthorized();
    }

    public function test_fixed_code_is_disabled_by_default_and_cannot_sign_in_staff(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_sms_login' => false]);
        $input = ['mobile' => '+34600000000', 'code' => '123456'];
        $this->postJson('/api/app/auth/sms-login', $input)->assertNotFound();
        config(['remit.local_sms_login' => true]);
        User::factory()->create(['phone_e164' => $input['mobile'], 'is_platform_staff' => true]);
        $this->postJson('/api/app/auth/sms-login', $input)->assertJsonPath('code', 1001);
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

    public function test_platform_token_cannot_be_revoked_by_member_logout(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $token = $staff->createToken('platform');

        $this->withToken($token->plainTextToken)->postJson('/api/app/auth/logout')->assertForbidden();
        $this->assertModelExists($token->accessToken);
    }
}
