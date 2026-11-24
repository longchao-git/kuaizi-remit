<?php

namespace Tests\Feature;

use App\Model\Notification\MemberNotification;
use App\Model\Remittance\YabandMaterialRequest;
use App\Model\System\User;
use App\Providers\AppServiceProvider;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Notification\MemberNotificationService;
use App\Service\Remittance\Yaband\MemberKycService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Tests\TestCase;

final class YabandQuestionNoticeTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array', 'broadcasting.default' => 'log',
            'yaband_remit.mid' => 'fixture-mid', 'yaband_remit.secret_key' => 'fixture-key',
            'yaband_remit.callback_verification_enabled' => true, 'yaband_remit.external_uid_prefix' => 'fixture']);
        $this->app->register(AppServiceProvider::class, true);
        $this->member = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($this->member->id), '101', null);
        Http::preventStrayRequests();
    }

    public function test_signed_question_on_kyc_notify_url_creates_one_owned_task_and_reminder(): void
    {
        $data = $this->data();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($data))->assertOk()->assertContent('ok');
        $this->postJson('/api/callbacks/yaband/remit/kyc', $this->signed($data))->assertOk()->assertContent('ok');
        $this->assertDatabaseCount('yaband_material_requests', 1);
        $this->assertDatabaseCount('member_notifications', 1);
        $task = YabandMaterialRequest::query()->sole();
        $this->assertSame('fixture-question', $task->question_id);
        $this->assertSame($this->member->id, $task->member_id);
        $this->assertNull($task->order_reference);
        $this->assertSame('请补充资金来源说明', $task->instruction);
        $this->assertSame($data['timeout_at'], $task->deadline_at->timestamp);
        $this->assertSame($task->reference, MemberNotification::query()->sole()->material_reference);
        Http::assertNothingSent();
    }

    public function test_tampered_wrong_mid_wrong_endpoint_and_unknown_member_cannot_create_tasks(): void
    {
        $body = $this->signed($this->data());
        $body['data']['q_description'] = 'tampered';
        $this->postJson('/callbacks/yaband/remit/kyc', $body)->assertForbidden();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed([...$this->data(), 'mid' => 'other']))->assertForbidden();
        $this->postJson('/callbacks/yaband/remit/transaction', $this->signed($this->data()))->assertForbidden();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed([...$this->data(), 'user' => '{"uid":"unknown","external_uid":"unknown"}']))->assertNotFound();
        $this->assertDatabaseCount('yaband_material_requests', 0);
        $this->assertDatabaseCount('member_notifications', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidQuestions')]
    public function test_malformed_question_is_rejected_without_side_effects(array $changes): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed([...$this->data(), ...$changes]))->assertUnprocessable();
        $this->assertDatabaseCount('yaband_material_requests', 0);
        $this->assertDatabaseCount('member_notifications', 0);
    }

    public static function invalidQuestions(): iterable
    {
        yield 'missing-question' => [['qno' => null]];
        yield 'long-question' => [['qno' => str_repeat('a', 201)]];
        yield 'empty-instruction' => [['q_description' => ' ']];
        yield 'wrong-deadline-type' => [['timeout_at' => '1800000000']];
        yield 'out-of-range-deadline' => [['timeout_at' => 253402300800]];
        yield 'malformed-user' => [['user' => '{']];
        yield 'object-user-not-string' => [['user' => ['uid' => 101, 'external_uid' => 'fixture']]];
        yield 'missing-external' => [['user' => '{"uid":101}']];
        yield 'object-transaction' => [['transaction' => '{}']];
        yield 'unconfirmed-order-association' => [['transaction' => '{"order_id":"other"}']];
    }

    public function test_conflicting_retry_cannot_change_instruction_or_deadline(): void
    {
        $data = $this->data();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($data))->assertOk();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed([...$data, 'q_description' => '替换要求']))->assertConflict();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed([...$data, 'timeout_at' => $data['timeout_at'] + 3600]))->assertConflict();
        $this->assertDatabaseCount('member_notifications', 1);
        $this->assertSame($data['q_description'], YabandMaterialRequest::query()->sole()->instruction);
    }

    public function test_late_expired_notice_is_recorded_closed_without_new_reminder(): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed([...$this->data(), 'timeout_at' => now()->subMinute()->timestamp]))->assertOk();
        $this->assertSame('6003', YabandMaterialRequest::query()->sole()->status);
        $this->assertDatabaseCount('member_notifications', 0);
    }

    public function test_completed_task_is_not_reopened_by_redelivery(): void
    {
        $data = $this->data();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($data))->assertOk();
        YabandMaterialRequest::query()->sole()->forceFill(['status' => '6002'])->saveOrFail();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($data))->assertOk();
        $this->assertSame('6002', YabandMaterialRequest::query()->sole()->status);
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_member_can_read_linked_task_but_other_member_and_anonymous_cannot(): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($this->data()))->assertOk();
        $task = YabandMaterialRequest::query()->sole();
        $path = '/app/remittance/materials/'.$task->reference;
        $this->getJson($path)->assertUnauthorized();
        $this->withToken($this->member->createToken('member')->plainTextToken)->getJson($path)
            ->assertOk()->assertJsonPath('data.reference', $task->reference)->assertJsonMissingPath('data.question_id');
        $this->getJson('/app/notifications')->assertJsonPath('data.list.0.material_reference', $task->reference);
        $this->app['auth']->forgetGuards();
        $other = User::factory()->create();
        $this->withToken($other->createToken('member')->plainTextToken)->getJson($path)->assertNotFound();
    }

    public function test_saved_external_binding_is_checked_without_rebinding_from_notice(): void
    {
        $link = app(YabandRemitMemberRepository::class)->findFor($this->member->id);
        $link->forceFill(['external_uid' => 'original-external'])->saveOrFail();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($this->data()))->assertForbidden();
        $this->assertSame('original-external', $link->fresh()->external_uid);
        $this->assertDatabaseCount('yaband_material_requests', 0);
    }

    public function test_same_question_cannot_be_assigned_to_a_different_member(): void
    {
        $data = $this->data();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($data))->assertOk();
        $other = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($other->id), '102', null);
        $data['user'] = json_encode(['uid' => 102, 'external_uid' => 'fixture-'.$other->id], JSON_THROW_ON_ERROR);
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($data))->assertConflict();
        $this->assertSame($this->member->id, YabandMaterialRequest::query()->sole()->member_id);
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_identity_lock_contention_does_not_drop_or_duplicate_task(): void
    {
        $lock = Cache::lock('yaband-remit:kyc:'.$this->member->id, 75);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($this->data()))->assertConflict();
        } finally {
            $lock->release();
        }
        $this->assertDatabaseCount('yaband_material_requests', 0);
    }

    public function test_notification_conflict_rolls_back_new_task(): void
    {
        $data = $this->data();
        $requestId = Uuid::uuid5(Uuid::NAMESPACE_URL, json_encode(['yaband-question', $data['mid'], $this->member->id, $data['qno']], JSON_THROW_ON_ERROR));
        MemberNotification::query()->create(['member_id' => $this->member->id, 'request_id' => (string) $requestId,
            'title' => 'Existing conflicting reminder', 'body' => 'Different content']);
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($data))->assertServiceUnavailable();
        $this->assertDatabaseCount('yaband_material_requests', 0);
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_long_question_is_kept_whole_and_unsafe_rollback_stops_before_losing_columns(): void
    {
        $instruction = str_repeat('完整补件要求', 120);
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed([...$this->data(), 'q_description' => $instruction]))->assertOk();
        $this->assertSame($instruction, YabandMaterialRequest::query()->sole()->instruction);
        $migration = require database_path('migrations/2026_11_24_000002_link_material_notifications.php');
        try {
            $migration->down();
            $this->fail('A lossy rollback was allowed.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('不能无损回退', $error->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('member_notifications', 'material_reference'));
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_short_data_survives_migration_rollback_and_reapply(): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($this->data()))->assertOk();
        $migration = require database_path('migrations/2026_11_24_000002_link_material_notifications.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('member_notifications', 'material_reference'));
        $this->assertDatabaseCount('member_notifications', 1);
        $this->assertDatabaseCount('yaband_material_requests', 1);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('member_notifications', 'material_reference'));
        $this->assertSame('fixture-question', YabandMaterialRequest::query()->sole()->question_id);
    }

    public function test_deleted_task_removes_only_notification_link(): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($this->data()))->assertOk();
        YabandMaterialRequest::query()->sole()->delete();
        $this->assertNull(MemberNotification::query()->sole()->material_reference);
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_notification_cannot_link_another_members_task(): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->signed($this->data()))->assertOk();
        $other = User::factory()->create();
        $result = app(MemberNotificationService::class)->send($other->id, null, [
            'request_id' => (string) Str::uuid(), 'title' => 'Wrong owner', 'body' => 'Must be rejected',
            'material_reference' => YabandMaterialRequest::query()->sole()->reference,
        ]);
        $this->assertTrue($result->isFail());
        $this->assertSame(404, $result->getCode());
        $this->assertDatabaseCount('member_notifications', 1);
    }

    public function test_identity_creation_retry_keeps_original_external_uid_when_prefix_changes(): void
    {
        config(['yaband_remit.base_url' => 'https://question.fixture.invalid', 'yaband_remit.password' => 'fixture-password',
            'yaband_remit.kyc_notify_url' => 'https://app.fixture.invalid/notify',
            'yaband_remit.kyc_redirect_url' => 'https://app.fixture.invalid/return']);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            if (str_ends_with($request->url(), '/api/auth/login')) {
                return Http::response(['status' => true, 'code' => 200, 'data' => [
                    'token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp,
                ]]);
            }
            $requests[] = $request;

            return Http::failedConnection();
        });
        $newMember = User::factory()->create();
        $this->assertTrue(app(MemberKycService::class)->start($newMember)->isFail());
        $link = app(YabandRemitMemberRepository::class)->findFor($newMember->id);
        $this->assertSame('fixture-'.$newMember->id, $link->external_uid);
        config(['yaband_remit.external_uid_prefix' => 'changed']);
        $this->assertTrue(app(MemberKycService::class)->start($newMember)->isFail());
        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertStringEndsWith('/api/kyc/new', $request->url());
            $this->assertSame($link->external_uid, $request['data']['user']['external_uid']);
            $this->assertSame($link->create_request_id, $request['data']['request_id']);
        }
    }

    private function data(): array
    {
        return ['type' => 'question', 'mid' => 'fixture-mid', 'qno' => 'fixture-question', 'qid' => 'fixture-template',
            'q_description' => '请补充资金来源说明', 'timeout_at' => now()->addHour()->timestamp,
            'create_at' => now()->timestamp, 'update_at' => now()->timestamp,
            'user' => json_encode(['uid' => 101, 'external_uid' => 'fixture-'.$this->member->id], JSON_THROW_ON_ERROR),
            'transaction' => '[]'];
    }

    private function signed(array $data): array
    {
        $sorted = $data;
        ksort($sorted);
        $pairs = [];
        foreach ($sorted as $key => $value) {
            $pairs[] = $key.'='.(is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value);
        }

        return ['data' => $data, 'sign' => hash_hmac('sha256', implode('&', $pairs), 'fixture-key')];
    }
}
