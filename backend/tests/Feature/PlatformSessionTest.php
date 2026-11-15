<?php

namespace Tests\Feature;

use App\Constants\Common\ErrorCode;
use App\Model\System\User;
use App\Permission\RemittancePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_load_session_and_revoke_their_token(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $this->postJson('/api/user/login', ['username' => $staff->email, 'password' => 'wrong'])
            ->assertJsonPath('code', ErrorCode::INVALID_CREDENTIALS);
        $token = $this->postJson('/api/user/login', ['username' => $staff->email, 'password' => 'password'])
            ->assertJsonPath('code', 0)->json('data.token');

        $this->withToken($token)->getJson('/api/user/current')->assertJsonPath('data.username', $staff->email);
        $this->getJson('/api/user/menus')
            ->assertJsonPath('data.0.component', 'remittance/dashboard')
            ->assertJsonPath('data.3.name', '报价与规则')
            ->assertJsonPath('data.3.children.0.component', 'remittance/rules')
            ->assertJsonPath('data.3.children.0.path', '/system/remittance/rules');
        $this->getJson('/api/user/permissions')->assertJsonPath('data.0', RemittancePermission::MANAGE_RULES);
        $this->postJson('/api/user/logout')->assertJsonPath('code', 0);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user/current')->assertUnauthorized();
    }

    public function test_menu_groups_include_only_pages_available_to_the_staff_role(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'finance']);
        $token = $this->postJson('/api/user/login', ['username' => $staff->email, 'password' => 'password'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->getJson('/api/user/menus')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.component', 'remittance/dashboard')
            ->assertJsonPath('data.1.children.0.component', 'remittance/orders')
            ->assertJsonCount(1, 'data.1.children')
            ->assertJsonPath('data.2.children.0.component', 'remittance/reconciliation')
            ->assertJsonCount(1, 'data.2.children');
        $this->getJson('/api/admin/remittance/rules')->assertForbidden();
        $this->getJson('/api/admin/remittance/reconciliation')->assertOk();
    }

    public function test_member_cannot_log_into_the_platform(): void
    {
        $member = User::factory()->create();
        $this->postJson('/api/user/login', ['username' => $member->email, 'password' => 'password'])
            ->assertJsonPath('code', ErrorCode::INVALID_CREDENTIALS);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
