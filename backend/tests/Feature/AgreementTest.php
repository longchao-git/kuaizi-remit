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

    public function test_saved_drafts_are_private_and_survive_reloading_without_changing_published_text(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->withToken($staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken);
        $published = $this->postJson('/api/admin/remittance/agreements/terms', ['body' => '原公开正文'])
            ->assertOk()->json('data.version');
        $this->putJson('/api/admin/remittance/agreements/terms/draft', [
            'body' => "待确认用户协议\n第二段", 'draft_revision' => null, 'updated_by' => 99999,
        ])->assertOk()->assertJsonPath('data.body', "待确认用户协议\n第二段");
        $this->putJson('/api/admin/remittance/agreements/privacy/draft', [
            'body' => '待确认隐私正文', 'draft_revision' => null,
        ])->assertOk();

        $this->getJson('/api/admin/remittance/agreements/terms')->assertOk()
            ->assertJsonPath('data.document.version', $published)
            ->assertJsonPath('data.document.body', '原公开正文')
            ->assertJsonPath('data.draft.body', "待确认用户协议\n第二段")
            ->assertJsonMissingPath('data.draft.updated_by');
        $this->getJson('/api/admin/remittance/agreements/privacy')->assertOk()
            ->assertJsonPath('data.document', null)
            ->assertJsonPath('data.draft.body', '待确认隐私正文');
        $this->getJson('/api/app/agreements/terms')->assertJsonPath('data.document.body', '原公开正文')
            ->assertJsonMissingPath('data.draft');
        $this->getJson('/api/app/agreements/privacy')->assertJsonPath('data.document', null);
        $this->assertDatabaseHas('remit_agreement_drafts', ['kind' => 'terms', 'updated_by' => $staff->id]);
        $this->assertDatabaseCount('remit_agreement_versions', 1);
    }

    public function test_stale_draft_saves_are_rejected_without_overwriting_the_latest_body(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->withToken($staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken);
        $path = '/api/admin/remittance/agreements/terms/draft';
        $first = $this->putJson($path, ['body' => '第一次保存', 'draft_revision' => null])
            ->assertOk()->json('data.revision');
        $this->putJson($path, ['body' => '另一个空白页的旧输入', 'draft_revision' => null])->assertStatus(409);
        $next = $this->putJson($path, ['body' => '最新已保存正文', 'draft_revision' => $first])
            ->assertOk()->json('data.revision');
        $this->assertNotSame($first, $next);
        $this->putJson($path, ['body' => '旧页面不得覆盖', 'draft_revision' => $first])->assertStatus(409);
        $this->getJson('/api/admin/remittance/agreements/terms')->assertJsonPath('data.draft.body', '最新已保存正文');
        $this->assertDatabaseCount('remit_agreement_drafts', 1);
        $this->assertDatabaseCount('remit_agreement_versions', 0);
    }

    public function test_publishing_checks_the_saved_draft_and_only_then_removes_it(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->withToken($staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken);
        $path = '/api/admin/remittance/agreements/terms';
        $first = $this->putJson($path.'/draft', ['body' => '旧草稿', 'draft_revision' => null])
            ->assertOk()->json('data.revision');
        $latest = $this->putJson($path.'/draft', ['body' => '新草稿', 'draft_revision' => $first])
            ->assertOk()->json('data.revision');
        $this->postJson($path, ['body' => '旧草稿', 'draft_revision' => $first])->assertStatus(409);
        $this->postJson($path, ['body' => '以为没有草稿的页面', 'draft_revision' => null])->assertStatus(409);
        $this->assertDatabaseCount('remit_agreement_versions', 0);
        $this->assertDatabaseHas('remit_agreement_drafts', ['kind' => 'terms', 'body' => '新草稿']);

        $this->postJson($path, ['body' => '确认后的发布正文', 'draft_revision' => $latest])->assertOk();
        $this->assertDatabaseCount('remit_agreement_drafts', 0);
        $this->getJson('/api/app/agreements/terms')->assertJsonPath('data.document.body', '确认后的发布正文');
        $this->getJson($path)->assertJsonPath('data.draft', null);

        $this->putJson($path.'/draft', ['body' => '下一轮待确认稿', 'draft_revision' => null])->assertOk();
        $this->postJson($path, ['body' => '上一轮迟到的发布', 'draft_revision' => $latest])->assertStatus(409);
        $this->assertDatabaseHas('remit_agreement_drafts', ['kind' => 'terms', 'body' => '下一轮待确认稿']);
    }

    public function test_legacy_publish_does_not_delete_a_draft_saved_by_another_editor(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->withToken($staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken);
        $path = '/api/admin/remittance/agreements/privacy';
        $this->putJson($path.'/draft', ['body' => '仍待确认', 'draft_revision' => null])->assertOk();
        $this->postJson($path, ['body' => '旧客户端明确发布'])->assertOk();
        $this->getJson($path)->assertJsonPath('data.document.body', '旧客户端明确发布')
            ->assertJsonPath('data.draft.body', '仍待确认');
    }

    public function test_draft_access_requires_editor_permissions_and_input_is_validated(): void
    {
        $path = '/api/admin/remittance/agreements/terms';
        $input = ['body' => '不得写入', 'draft_revision' => null];
        $this->getJson($path)->assertUnauthorized();
        $this->putJson($path.'/draft', $input)->assertUnauthorized();
        foreach ([['is_platform_staff' => true, 'platform_role' => 'read_only'], ['is_platform_staff' => false]] as $attributes) {
            $user = User::factory()->create($attributes);
            $this->app['auth']->forgetGuards();
            $this->withToken($user->createToken($user->is_platform_staff ? 'platform' : 'app', PlatformRoles::abilities($user))->plainTextToken);
            $this->getJson($path)->assertForbidden();
            $this->putJson($path.'/draft', $input)->assertForbidden();
        }
        $this->assertDatabaseCount('remit_agreement_drafts', 0);

        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('platform', PlatformRoles::abilities($staff))->plainTextToken);
        $this->putJson($path.'/draft', ['body' => ' ', 'draft_revision' => null])->assertUnprocessable();
        $this->putJson($path.'/draft', ['body' => str_repeat('字', 30001), 'draft_revision' => null])->assertUnprocessable();
        $this->putJson($path.'/draft', ['body' => '缺少修订字段'])->assertUnprocessable();
        $this->putJson($path.'/draft', ['body' => '非法修订', 'draft_revision' => 'invalid'])->assertUnprocessable();
        $this->putJson('/api/admin/remittance/agreements/unknown/draft', $input)->assertNotFound();
        $this->putJson($path.'/draft', ['body' => str_repeat('字', 30000), 'draft_revision' => null])->assertOk();
        $this->assertDatabaseCount('remit_agreement_versions', 0);
    }
}
