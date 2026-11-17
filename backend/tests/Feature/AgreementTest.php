<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AgreementTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_published_agreements_are_public_and_keep_distinct_versions(): void
    {
        $terms = '/api/app/agreements/terms';
        $publish = '/api/admin/remittance/agreements/';
        $this->getJson($terms)->assertOk()->assertJsonPath('data.document', null);
        $this->postJson($publish.'terms', ['body' => '正文'])->assertUnauthorized();

        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $token = $staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken;
        $this->withToken($token)->postJson($publish.'terms', ['body' => ' '])->assertUnprocessable();
        $this->postJson($publish.'terms', ['body' => str_repeat('字', 30001)])->assertUnprocessable();
        $this->postJson($publish.'unknown', ['body' => '无效类型'])->assertNotFound();
        $old = $this->postJson($publish.'terms', ['body' => '旧版协议'])->assertOk()->json('data.version');
        $next = $this->postJson($publish.'terms', ['body' => "新版协议\n第二段"])->assertOk()->json('data.version');
        $this->assertNotSame($old, $next);
        $this->postJson($publish.'privacy', ['body' => '隐私政策正文'])->assertOk();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $this->getJson($terms)->assertOk()
            ->assertJsonPath('data.document.version', $next)
            ->assertJsonPath('data.document.body', "新版协议\n第二段")
            ->assertJsonMissingPath('data.document.published_by');
        $this->getJson('/api/app/agreements/privacy')->assertJsonPath('data.document.body', '隐私政策正文');
        $this->assertDatabaseHas('remit_agreement_versions', ['id' => $old, 'published_by' => $staff->id, 'body' => '旧版协议']);
    }

    public function test_read_only_staff_and_members_cannot_publish(): void
    {
        $path = '/api/admin/remittance/agreements/terms';
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'read_only']);
        $member = User::factory()->create();
        $this->withToken($staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken)
            ->postJson($path, ['body' => '受限'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('app')->plainTextToken)
            ->postJson($path, ['body' => '受限'])->assertForbidden();
        $this->assertDatabaseCount('remit_agreement_versions', 0);
    }
}
