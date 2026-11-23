<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApiRootTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_and_legacy_health_routes_are_available_and_names_generate_canonical_urls(): void
    {
        foreach (['/', '/health', '/api/', '/api/health'] as $path) {
            $this->get($path)->assertOk()->assertExactJson([
                'code' => 0, 'msg' => '操作成功', 'data' => ['status' => 'ok'],
            ]);
        }
        $this->assertSame('/health', route('api.health', absolute: false));
        $this->assertSame('/api/health', route('legacy.api.health', absolute: false));
        $this->assertSame('/app/support/messages', route('support.member.index', absolute: false));
        $this->assertSame('/api/app/support/messages', route('legacy.support.member.index', absolute: false));
        $this->assertSame('api.health', Route::getRoutes()->getByName('api.health')->getName());
    }

    public function test_root_errors_are_json_without_relying_on_accept_headers(): void
    {
        $this->get('/unknown-route')->assertNotFound()->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('code', 404);
        $this->get('/user/current')->assertUnauthorized()->assertHeaderMissing('Location')
            ->assertJsonPath('code', 401);
        $this->post('/user/login')->assertUnprocessable()->assertJsonPath('code', 400);
        $this->post('/health')->assertStatus(405)->assertHeader('Allow', 'GET, HEAD')
            ->assertJsonPath('code', 405);
    }

    public function test_root_login_and_legacy_routes_share_the_same_staff_session_and_permissions(): void
    {
        $staff = User::factory()->create(['username' => 'root-api-tester', 'is_platform_staff' => true]);
        config(['remit.platform_admin_emails' => [$staff->email]]);
        $token = $this->postJson('/user/login', ['username' => $staff->username, 'password' => 'password'])
            ->assertOk()->assertJsonPath('code', 0)->json('data.token');
        $this->withToken($token);
        foreach (['', '/api'] as $prefix) {
            $this->getJson($prefix.'/user/current')->assertJsonPath('data.id', (string) $staff->id);
            $this->getJson($prefix.'/user/permissions')->assertJsonFragment([PlatformRoles::MANAGE_ACCESS]);
            $this->getJson($prefix.'/admin/remittance/agreements/terms')->assertOk()
                ->assertJsonPath('data.document', null)->assertJsonPath('data.draft', null);
            $this->getJson($prefix.'/app/auth/current')->assertForbidden();
        }
        $this->postJson('/user/logout')->assertJsonPath('code', 0);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/user/current')->assertUnauthorized();
    }

    public function test_member_named_routes_keep_member_semantics_on_both_paths(): void
    {
        $member = User::factory()->create();
        $this->withToken($member->createToken('app')->plainTextToken);
        foreach (['', '/api'] as $prefix) {
            foreach (['/app/coupons', '/app/support/tickets', '/app/support/messages'] as $path) {
                $this->getJson($prefix.$path)->assertOk()->assertJsonPath('code', 0);
            }
            $this->getJson($prefix.'/user/current')->assertForbidden();
        }
    }

    public function test_admin_origin_can_preflight_and_read_success_and_error_responses_on_both_paths(): void
    {
        $origin = 'https://admin.kzremit.com';
        foreach (['', '/api'] as $prefix) {
            $response = $this->options($prefix.'/user/login', [], [
                'Origin' => $origin,
                'Access-Control-Request-Method' => 'POST',
                'Access-Control-Request-Headers' => 'authorization,content-type',
            ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
                ->assertHeaderMissing('Access-Control-Allow-Credentials');
            $allowed = array_map('trim', explode(',', strtolower($response->headers->get('Access-Control-Allow-Headers'))));
            $this->assertContains('authorization', $allowed);
            $this->assertContains('content-type', $allowed);
            $this->get($prefix.'/health', ['Origin' => $origin])->assertOk()
                ->assertHeader('Access-Control-Allow-Origin', $origin)->assertJsonPath('code', 0);
            $this->get($prefix.'/user/current', ['Origin' => $origin])->assertUnauthorized()
                ->assertHeader('Access-Control-Allow-Origin', $origin)->assertJsonPath('code', 401);
            $this->get($prefix.'/unknown-route', ['Origin' => $origin])->assertNotFound()
                ->assertHeader('Access-Control-Allow-Origin', $origin)->assertJsonPath('code', 404);
        }
    }

    public function test_member_data_remains_isolated_with_canonical_and_legacy_route_names(): void
    {
        Event::fake();
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $staffToken = $staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken;
        $members = [User::factory()->create(), User::factory()->create()];
        $tokens = [];
        $ticketIds = [];
        foreach ($members as $member) {
            $this->app['auth']->forgetGuards();
            $this->withToken($staffToken)->postJson('/admin/remittance/coupons', [
                'request_id' => (string) Str::uuid(), 'title' => 'coupon-'.$member->id,
                'discount_bps' => 5000, 'valid_days' => 1, 'member_id' => $member->id,
            ])->assertOk()->assertJsonPath('code', 0);
            $this->app['auth']->forgetGuards();
            $tokens[] = $member->createToken('app')->plainTextToken;
            $this->withToken(end($tokens));
            $ticketIds[] = $this->postJson('/app/support/tickets', [
                'client_id' => (string) Str::uuid(), 'subject' => 'ticket-'.$member->id, 'body' => '会员工单',
            ])->assertOk()->assertJsonPath('code', 0)->json('data.id');
            $this->postJson('/app/support/messages', [
                'client_id' => (string) Str::uuid(), 'body' => 'message-'.$member->id,
            ])->assertOk()->assertJsonPath('code', 0);
        }

        $this->app['auth']->forgetGuards();
        $this->withToken($tokens[0]);
        foreach (['', '/api'] as $prefix) {
            $this->getJson($prefix.'/app/coupons')->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.list.0.title', 'coupon-'.$members[0]->id);
            $this->getJson($prefix.'/app/support/tickets')->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.list.0.subject', 'ticket-'.$members[0]->id);
            $this->getJson($prefix.'/app/support/tickets/'.$ticketIds[1])->assertNotFound();
            $this->getJson($prefix.'/app/support/messages')->assertJsonCount(1, 'data.list')
                ->assertJsonPath('data.list.0.body', 'message-'.$members[0]->id);
        }
    }

    public function test_unlisted_origins_are_not_granted_browser_access(): void
    {
        $this->options('/user/login', [], [
            'Origin' => 'https://untrusted.example',
            'Access-Control-Request-Method' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');
        $this->get('/health', ['Origin' => 'https://untrusted.example'])
            ->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_broadcast_authentication_is_protected_on_root_and_legacy_paths(): void
    {
        foreach (['/broadcasting/auth', '/api/broadcasting/auth'] as $path) {
            $this->postJson($path, ['socket_id' => '1.2', 'channel_name' => 'private-support.staff'])
                ->assertUnauthorized()->assertJsonPath('code', 401);
        }
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'support']);
        $this->withToken($staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken);
        foreach (['/broadcasting/auth', '/api/broadcasting/auth'] as $path) {
            $this->postJson($path, ['socket_id' => '1.2', 'channel_name' => 'private-support.staff'])
                ->assertOk()->assertJsonStructure(['auth']);
        }
    }
}
