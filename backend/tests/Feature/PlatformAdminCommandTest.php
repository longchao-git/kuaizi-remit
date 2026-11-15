<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_admin_that_can_log_in(): void
    {
        config(['remit.platform_admin_emails' => ['root@kuaizi.local']]);

        $this->artisan('remit:create-admin', [
            'email' => 'Root@Kuaizi.local',
            '--name' => '平台管理员',
            '--password' => 'admin-pass-2026',
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', [
            'email' => 'root@kuaizi.local',
            'name' => '平台管理员',
            'is_platform_staff' => true,
        ]);

        $token = $this->postJson('/api/user/login', [
            'username' => 'root@kuaizi.local',
            'password' => 'admin-pass-2026',
        ])->assertJsonPath('code', 0)->json('data.token');

        $this->withToken($token)->getJson('/api/user/permissions')
            ->assertJsonFragment([PlatformRoles::MANAGE_ACCESS]);
    }

    public function test_existing_account_requires_force_to_reset(): void
    {
        User::factory()->create(['email' => 'root@kuaizi.local']);

        $this->artisan('remit:create-admin', [
            'email' => 'root@kuaizi.local',
            '--password' => 'admin-pass-2026',
        ])->assertFailed();

        $this->assertDatabaseHas('users', [
            'email' => 'root@kuaizi.local',
            'is_platform_staff' => false,
        ]);
    }
}
