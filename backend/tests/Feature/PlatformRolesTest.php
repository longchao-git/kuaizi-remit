<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Permission\RemittancePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlatformRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_wildcard_does_not_grant_staff_management(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $token = $staff->createToken('platform')->plainTextToken;

        $this->withToken($token)->getJson('/api/user/permissions')->assertOk()
            ->assertJsonMissing([PlatformRoles::MANAGE_ACCESS]);
        $this->getJson('/api/admin/remittance/staff')->assertForbidden();
        $this->getJson('/api/admin/remittance/rules')->assertOk();
    }

    public function test_configured_admin_can_assign_roles_and_old_privileges_are_revoked(): void
    {
        $administrator = User::factory()->create(['is_platform_staff' => true]);
        $staff = User::factory()->create(['is_platform_staff' => true]);
        config(['remit.platform_admin_emails' => [strtolower($administrator->email)]]);
        $path = '/api/admin/remittance/staff';
        $old = $staff->createToken('platform', [RemittancePermission::MANAGE_RULES])->plainTextToken;

        $this->withToken($old)->getJson($path)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $token = $administrator->createToken('platform', [PlatformRoles::MANAGE_ACCESS])->plainTextToken;
        $this->withToken($token)->putJson($path.'/'.$administrator->id.'/role', ['role' => 'support'])->assertConflict();
        $this->putJson($path.'/'.$staff->id.'/role', ['role' => 'administrator'])->assertUnprocessable();
        $this->putJson($path.'/'.$staff->id.'/role', ['role' => 'support'])->assertOk()->assertJsonPath('data.role', 'support');
        $this->putJson($path.'/'.$staff->id.'/role', ['role' => 'support'])->assertOk();
        $this->getJson($path.'/role-audits')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.operator_name', $administrator->name)
            ->assertJsonPath('data.list.0.before_role', null)->assertJsonPath('data.list.0.after_role', 'support');

        $this->app['auth']->forgetGuards();
        $this->withToken($old)->getJson('/api/user/current')->assertUnauthorized();
        $newToken = $this->postJson('/api/user/login', ['username' => $staff->email, 'password' => 'password'])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();
        $this->withToken($newToken)->getJson('/api/user/permissions')
            ->assertJsonPath('data.0', RemittancePermission::SUPPORT)
            ->assertJsonPath('data.1', RemittancePermission::VIEW_MEMBERS)
            ->assertJsonPath('data.2', RemittancePermission::VIEW_STATUS_REASONS);
        $this->getJson('/api/admin/remittance/coupons')->assertForbidden();
        $this->getJson('/api/admin/support/conversations')->assertOk();
    }

    public function test_status_reason_lookup_is_read_only_and_available_to_support(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'support']);
        $path = '/api/admin/remittance/status-reasons';
        $this->getJson($path)->assertUnauthorized();
        $this->withToken($staff->createToken('platform', [RemittancePermission::VIEW_STATUS_REASONS])->plainTextToken)
            ->getJson($path)->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($path.'?keyword=kyc')->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'KYC_PENDING_REVIEW');
        $this->getJson($path.'?keyword='.urlencode('重复付款'))->assertJsonPath('data.0.code', 'PAYMENT_CHANNEL_PROCESSING');
        $this->getJson($path.'?keyword=%25')->assertJsonCount(0, 'data');
        $this->putJson($path, [])->assertMethodNotAllowed();
    }

    public function test_admin_audit_lists_persisted_actions_without_exposing_coupon_codes_or_messages(): void
    {
        $admin = User::factory()->create(['is_platform_staff' => true]);
        $member = User::factory()->create();
        config(['remit.platform_admin_emails' => [$admin->email]]);
        $abilities = [PlatformRoles::MANAGE_ACCESS, RemittancePermission::MANAGE_COUPONS, RemittancePermission::SEND_NOTIFICATION];
        $path = '/api/admin/remittance';
        $this->withToken($admin->createToken('platform', $abilities)->plainTextToken);
        $coupon = $this->postJson($path.'/coupons', [
            'request_id' => (string) Str::uuid(), 'title' => '折扣券', 'discount_bps' => 2000, 'valid_days' => 7,
        ])->assertOk()->json('data');
        $this->postJson($path.'/coupons/'.$coupon['id'].'/revoke')->assertOk();
        $this->postJson($path.'/members/'.$member->id.'/notifications', [
            'request_id' => (string) Str::uuid(), 'title' => '通知', 'body' => '私密消息正文',
        ])->assertOk();

        $this->getJson($path.'/audit')->assertOk()->assertJsonPath('data.total', 3)
            ->assertJsonMissing(['body' => '私密消息正文'])->assertJsonMissing(['code' => $coupon['code']]);
        $this->getJson($path.'/audit?action=coupon_revoked')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.reference_id', $coupon['id'])
            ->assertJsonPath('data.list.0.action', 'coupon_revoked')->assertJsonPath('data.list.0.operator_name', $admin->name);
        $this->getJson($path.'/audit?action=unknown')->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('app')->plainTextToken)->getJson($path.'/audit')->assertForbidden();
    }
}
