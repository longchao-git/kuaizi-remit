<?php

namespace Tests\Feature;

use App\Model\Remittance\MemberPayoutAccountDraft;
use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Permission\RemittancePermission;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\MemberKycService;
use App\Service\Remittance\Yaband\MemberMaterialNoticeService;
use App\Service\Remittance\Yaband\YabandKycStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class RemittanceFlowImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        config(['broadcasting.default' => 'log', 'yaband_remit.mid' => 'fixture-mid',
            'yaband_remit.base_url' => 'https://fixture.example.test', 'yaband_remit.password' => 'fixture-only']);
        Http::preventStrayRequests();
    }

    public function test_payout_drafts_are_encrypted_owned_masked_and_never_usable_for_payment(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $reference = (string) Str::uuid();
        $path = '/app/remittance/payout-account-drafts/'.$reference;
        $input = ['payout_method' => 'bank', 'first_name' => 'TEST', 'last_name' => 'ONLY', 'account' => 'fixture-account-8877', 'status' => 'verified'];
        $this->asMember($member);
        $saved = $this->putJson($path, $input)->assertOk()->assertJsonPath('data.status', 'pending_verification')
            ->assertJsonPath('data.can_pay', false)->assertJsonPath('data.masked_account', '****8877')
            ->assertJsonMissingPath('data.account')->json('data');
        $draft = MemberPayoutAccountDraft::query()->sole();
        $this->assertStringNotContainsString($input['account'], $draft->getRawOriginal('details'));
        $this->assertSame($input['account'], $draft->details['account']);
        $updated = $this->putJson($path, [...$input, 'account' => '', 'first_name' => 'UPDATED', 'revision' => $saved['revision']])
            ->assertOk()->assertJsonPath('data.masked_account', '****8877')->json('data');
        $this->assertNotSame($saved['revision'], $updated['revision']);
        $this->putJson($path, [...$input, 'revision' => $saved['revision']])->assertConflict();
        $this->putJson($path, [...$input, 'payout_method' => 'wechat', 'account' => '', 'revision' => $updated['revision']])->assertUnprocessable();
        $this->asMember($other);
        $this->getJson('/api/app/remittance/payout-account-drafts')->assertOk()->assertJsonPath('data.total', 0);
        $this->putJson($path, [...$input, 'revision' => $updated['revision']])->assertNotFound();
        $this->asMember($member);
        $this->getJson('/app/remittance/payout-account-drafts')->assertOk()->assertJsonPath('data.list.0.first_name', 'UPDATED');
        $this->getJson('/app/remittance/payout-accounts?payout_method=bank')->assertServiceUnavailable();
        $this->assertDatabaseCount('remittance_orders', 0);
        Http::assertNothingSent();
    }

    public function test_payout_draft_validation_rejects_empty_new_account_control_characters_and_oversize_fields(): void
    {
        $this->asMember(User::factory()->create());
        $path = '/app/remittance/payout-account-drafts/'.Str::uuid();
        $input = ['payout_method' => 'bank', 'first_name' => 'TEST', 'last_name' => 'ONLY'];
        $this->putJson($path, $input)->assertUnprocessable();
        $this->putJson($path, [...$input, 'account' => "fixture\naccount"])->assertUnprocessable();
        $this->putJson($path, [...$input, 'account' => str_repeat('a', 65)])->assertUnprocessable();
        $this->assertDatabaseCount('member_payout_account_drafts', 0);
        Http::assertNothingSent();
    }

    public function test_new_migration_can_reapply_without_data_but_refuses_lossy_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_24_000003_store_remittance_declarations_and_payout_drafts.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('member_payout_account_drafts'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('remittance_orders', 'declaration'));
        $this->asMember(User::factory()->create());
        $this->putJson('/app/remittance/payout-account-drafts/'.Str::uuid(), [
            'payout_method' => 'wechat', 'first_name' => 'TEST', 'last_name' => 'ONLY', 'account' => 'fixture-wechat',
        ])->assertOk();
        try {
            $migration->down();
            $this->fail('应阻止有用户资料时的有损回退');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('不能无损回退', $error->getMessage());
        }
        $this->assertDatabaseCount('member_payout_account_drafts', 1);
        $this->assertTrue(Schema::hasColumn('remittance_orders', 'declaration'));
    }

    public function test_draft_declaration_survives_reload_is_encrypted_and_cannot_be_changed_by_another_member(): void
    {
        $member = User::factory()->create();
        $record = app(RemittanceQuoteRecordRepository::class)->record($member->id, [
            'rule_revision_id' => null, 'principal_minor' => 10000, 'payment_method' => 'card', 'payout_method' => 'bank', 'mode' => 'local_estimate',
        ]);
        $declaration = ['source_of_funds' => '01', 'purpose' => '05', 'explanation' => '虚构的隔离测试用途'];
        $this->asMember($member);
        $this->putJson('/app/remittance/drafts/'.$record->reference, ['declaration' => $declaration])->assertOk()
            ->assertJsonPath('data.declaration', $declaration);
        $this->getJson('/app/remittance/drafts')->assertOk()->assertJsonPath('data.list.0.declaration', $declaration);
        $record->refresh();
        $this->assertStringNotContainsString('explanation', $record->getRawOriginal('declaration'));
        $this->asMember(User::factory()->create());
        $this->putJson('/app/remittance/drafts/'.$record->reference, ['declaration' => $declaration])->assertNotFound();
        $this->assertSame($declaration, $record->fresh()->declaration);
    }

    public function test_manual_and_automatic_material_registration_share_one_reminder_and_filters_use_effective_expiry(): void
    {
        $member = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($member->id), '101', null);
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->asMember($staff, 'platform', [RemittancePermission::MANAGE_MATERIALS]);
        $input = ['member_id' => $member->id, 'question_id' => 'shared-question', 'instruction' => '虚构补件要求', 'deadline_at' => now()->addMinute()->timestamp];
        $reference = $this->postJson('/admin/remittance/materials', $input)->assertOk()->json('data.reference');
        $this->postJson('/admin/remittance/materials', $input)->assertOk();
        $result = app(MemberMaterialNoticeService::class)->receive([
            'mid' => 'fixture-mid', 'qno' => $input['question_id'], 'q_description' => $input['instruction'],
            'timeout_at' => $input['deadline_at'], 'user' => '{"uid":"101","external_uid":"fixture"}', 'transaction' => '[]',
        ]);
        $this->assertTrue($result->isSuccess());
        $this->assertDatabaseCount('member_notifications', 1);
        $this->getJson('/admin/remittance/materials?member_id='.$member->id.'&question_id=shared-question&status=6001')
            ->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson('/admin/remittance/materials/'.$reference)->assertOk()->assertJsonPath('data.instruction', $input['instruction']);
        $this->travel(60)->seconds();
        $this->getJson('/admin/remittance/materials?status=6001')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/admin/remittance/materials?status=6003')->assertOk()->assertJsonPath('data.total', 1);
        $this->asMember($member);
        $this->getJson('/admin/remittance/materials/'.$reference)->assertForbidden();
        $this->getJson('/app/remittance/materials/'.$reference)->assertOk()->assertJsonPath('data.can_submit', false);
        $this->postJson('/app/remittance/materials/'.$reference, ['reply' => '已过期'])->assertConflict();
        $this->assertDatabaseCount('member_notifications', 1);
        Http::assertNothingSent();
    }

    public function test_manual_material_deadline_is_validated_before_database_writes(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->asMember($staff, 'platform', [RemittancePermission::MANAGE_MATERIALS]);
        $this->postJson('/admin/remittance/materials', [
            'member_id' => 1, 'question_id' => 'too-far', 'instruction' => '虚构要求', 'deadline_at' => 2147483648,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('yaband_material_requests', 0);
    }

    public function test_manual_material_reminder_failure_rolls_back_task(): void
    {
        $member = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($member->id), '101', null);
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->asMember($staff, 'platform', [RemittancePermission::MANAGE_MATERIALS]);
        config(['yaband_remit.mid' => null]);
        $this->postJson('/admin/remittance/materials', [
            'member_id' => $member->id, 'question_id' => 'no-mid', 'instruction' => '虚构要求', 'deadline_at' => now()->addHour()->timestamp,
        ])->assertServiceUnavailable();
        $this->assertDatabaseCount('yaband_material_requests', 0);
        $this->assertDatabaseCount('member_notifications', 0);
        Http::assertNothingSent();
    }

    public function test_member_active_query_is_limited_and_does_not_create_a_provider_trade(): void
    {
        $member = User::factory()->create();
        $order = new RemittanceOrder;
        $order->forceFill(['reference' => (string) Str::uuid(), 'member_id' => $member->id, 'white_label' => false,
            'principal_minor' => 10000, 'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00'])->saveOrFail();
        $path = '/app/remittance/orders/'.$order->reference.'/refresh';
        $this->asMember($member);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson($path)->assertConflict();
        }
        $this->postJson($path)->assertStatus(429);
        $this->assertNull($order->fresh()->provider_trade_id);
        Http::assertNothingSent();
    }

    public function test_member_query_checks_ownership_before_network_and_returns_only_member_fields(): void
    {
        $member = User::factory()->create();
        $order = new RemittanceOrder;
        $order->forceFill(['reference' => (string) Str::uuid(), 'provider_trade_id' => 'fixture-trade', 'member_id' => $member->id,
            'principal_minor' => 10000, 'white_label' => false, 'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00'])->saveOrFail();
        $path = '/app/remittance/orders/'.$order->reference.'/refresh';
        $this->asMember(User::factory()->create());
        $this->postJson($path)->assertNotFound();
        Http::assertNothingSent();
        $this->asMember($member);
        $this->signer();
        $transaction = ['order_id' => $order->reference, 'trade_id' => 'fixture-trade', 'income_status_code' => '00',
            'outgoing_status_code' => '05', 'refund_status_code' => '00'];
        Http::fake([
            '*/api/auth/login' => Http::response($this->reply(['token' => 'fixture-token', 'expires_in' => 600,
                'expires_at' => now()->addMinutes(10)->timestamp, 'token_type' => 'Bearer'])),
            '*/api/transaction/query' => Http::sequence()->push($this->reply(['transaction_info' => [...$transaction, 'order_id' => 'wrong']]))
                ->push($this->reply(['transaction_info' => $transaction]))->push($this->reply(['transaction_info' => $transaction])),
        ]);
        $this->postJson($path)->assertStatus(502);
        $this->assertSame('03', $order->fresh()->income_code);
        $this->postJson($path)->assertOk()->assertJsonPath('data.status_label', '已下发')
            ->assertJsonMissingPath('data.trade_id')->assertJsonMissingPath('data.member_id');
        $this->postJson('/api'.$path)->assertOk();
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_profile_guard_matches_app_and_guidance_does_not_expose_vendor_free_text(): void
    {
        $this->signer();
        config(['yaband_remit.kyc_notify_url' => 'https://fixture.example.test/notify']);
        $member = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($member->id), '101', null);
        $base = ['uid' => '101', 'idv_status_code' => '2006', 'kyc_status_code' => '5002', 'profile_status_code' => '3002'];
        Http::fake([
            '*/api/auth/login' => Http::response($this->reply(['token' => 'fixture-token', 'expires_in' => 600,
                'expires_at' => now()->addMinutes(10)->timestamp, 'token_type' => 'Bearer'])),
            '*/api/kyc/query' => Http::sequence()->push($this->reply($base))
                ->push($this->reply([...$base, 'kyc_status_code' => '5004', 'profile_status_code' => '3003'])),
        ]);
        $this->assertSame(409, app(MemberKycService::class)->submitProfile($member, [])->getCode());
        $this->assertSame(409, app(MemberKycService::class)->submitProfile($member, [])->getCode());
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/api/kyc/profile'));
        $safe = YabandKycStatus::fromQuery([...$base, 'idv_status_code' => '2007', 'idv_reason' => 'secret document and personal data']);
        $this->assertNotNull($safe['guidance']);
        $this->assertStringNotContainsString('secret', json_encode($safe));
        $this->assertArrayNotHasKey('idv_reason', $safe);
        $this->assertFalse($safe['can_submit_profile']);
        $approved = YabandKycStatus::fromQuery($base);
        $this->assertStringContainsString('已批准', $approved['guidance']);
        $this->assertStringNotContainsString('审核中', $approved['guidance']);
    }

    private function asMember(User $user, string $name = 'member', array $abilities = ['*']): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken($name, $abilities)->plainTextToken);
    }

    private function signer(): void
    {
        $this->app->instance(YabandRemitSigner::class, new class implements YabandRemitSigner
        {
            public function sign(string $path, array $payload): string
            {
                return 'fixture-signature';
            }
        });
    }

    private function reply(array $data): array
    {
        return ['status' => true, 'code' => 200, 'data' => $data];
    }
}
