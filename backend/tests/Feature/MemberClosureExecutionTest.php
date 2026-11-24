<?php

namespace Tests\Feature;

use App\Model\Remittance\MemberClosureRequest;
use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Providers\AppServiceProvider;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\MemberKycService;
use App\Service\Sms\SmsChannel;
use App\Service\System\MemberSessionService;
use App\Service\System\TestSmsLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MemberClosureExecutionTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private User $operator;

    private MemberClosureRequest $closure;

    private string $memberToken;

    private array $closeChanges = [];

    private bool $timeout = false;

    private string $queriedStatus = '1002';

    private bool $queryFails = false;

    private bool $orderAppears = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'cache.default' => 'array', 'broadcasting.default' => 'log',
            'yaband_remit.base_url' => 'https://closure.example.test', 'yaband_remit.mid' => 'fixture-mid',
            'yaband_remit.password' => 'fixture-password', 'yaband_remit.secret_key' => 'fixture-key',
            'yaband_remit.kyc_notify_url' => 'https://app.example.test/notify',
            'yaband_remit.kyc_redirect_url' => 'https://app.example.test/return', 'yaband_remit.external_uid_prefix' => 'fixture',
        ]);
        $this->app->register(AppServiceProvider::class, true);
        $this->member = User::factory()->create();
        $this->operator = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->memberToken = $this->member->createToken('member')->plainTextToken;
        $members = app(YabandRemitMemberRepository::class);
        $members->saveState($members->getOrCreate($this->member->id), '101', ['kyc_passed' => true, 'remittance_enabled' => true]);
        $this->closure = MemberClosureRequest::query()->forceCreate([
            'member_id' => $this->member->id, 'status' => 'approved', 'reviewed_by' => $this->operator->id,
            'review_note' => '已核对注销申请', 'reviewed_at' => now(),
        ]);
        $this->asUser($this->operator);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/auth/login') {
                $data = ['token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp];
            } elseif ($path === '/api/kyc/close_account') {
                if ($this->orderAppears) {
                    $this->unsettledOrder();
                }
                if ($this->timeout) {
                    return Http::failedConnection();
                }
                $data = [...['mid' => 'fixture-mid', 'type' => 'kyc', 'uid' => '101', 'status' => 'Inactive'], ...$this->closeChanges];
            } else {
                $this->assertSame('/api/kyc/query', $path);
                if ($this->queryFails) {
                    return Http::response(['status' => false, 'code' => -1010, 'message' => 'User not activated or not found']);
                }
                $data = ['mid' => 'fixture-mid', 'type' => 'kyc', 'uid' => '101', 'user_status_code' => $this->queriedStatus];
            }

            return Http::response(['status' => true, 'code' => '200', 'data' => $data]);
        });
    }

    public function test_execution_needs_staff_permission_explicit_confirmation_and_prior_approval(): void
    {
        $this->withToken('missing')->postJson($this->path())->assertUnauthorized();
        $this->asUser($this->member);
        $this->postJson($this->path(), ['confirm' => true])->assertForbidden();
        $this->asUser(User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'read_only']));
        $this->postJson($this->path(), ['confirm' => true])->assertForbidden();
        $this->asUser($this->operator);
        $this->postJson($this->path(), ['confirm' => false])->assertUnprocessable();
        foreach (['pending', 'rejected'] as $status) {
            $this->closure->forceFill(['status' => $status])->saveOrFail();
            $this->postJson($this->path(), ['confirm' => true])->assertConflict();
        }
        Http::assertNothingSent();
        $this->assertNull($this->member->fresh()->member_closed_at);
    }

    public function test_success_closes_local_login_releases_tokens_and_never_resends_close(): void
    {
        DB::table('member_push_devices')->insert(['member_id' => $this->member->id, 'token_id' => $this->member->tokens()->first()->id,
            'client_hash' => hash('sha256', 'closure-fixture-device'), 'client_id' => 'fixture-only', 'created_at' => now(), 'updated_at' => now()]);
        $this->postJson($this->path(), ['confirm' => true])->assertOk()->assertJsonPath('data.execution_status', 'completed');
        $done = $this->closure->fresh();
        $this->assertTrue(Str::isUuid($done->execution_request_id));
        $this->assertSame('101', $done->execution_uid);
        $this->assertSame($this->operator->id, $done->executed_by);
        $this->assertNotNull($done->executed_at);
        $this->assertNotNull($this->member->fresh()->member_closed_at);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('member_closure_requests', 1);
        $this->assertSame(0, $this->member->tokens()->count());
        $this->assertDatabaseCount('member_push_devices', 0);
        $this->assertSame(1, $this->operator->tokens()->count());
        $this->postJson($this->path(), ['confirm' => true])->assertOk()->assertJsonPath('data.execution_status', 'completed');
        $this->assertCount(1, $this->closeRequests());
        $request = $this->closeRequests()[0][0]['data'];
        $this->assertSame($done->execution_request_id, $request['request_id']);
        $this->assertSame('101', $request['user']['uid']);
        $this->assertFalse(app(YabandRemitMemberRepository::class)->findFor($this->member->id)->statuses['remittance_enabled']);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->memberToken)->getJson('/app/auth/current')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $lateToken = $this->member->createToken('member')->plainTextToken;
        $this->withToken($lateToken)->getJson('/app/auth/current')->assertUnauthorized();
        $this->app->instance('env', 'local');
        config(['remit.local_login_email' => $this->member->email]);
        $this->assertSame(403, app(MemberSessionService::class)->loginLocal($this->member->email, 'password')->getCode());
    }

    public function test_unsettled_orders_block_execution_without_reserving_a_request(): void
    {
        $this->unsettledOrder();
        $this->postJson($this->path(), ['confirm' => true])->assertConflict();
        $this->assertNull($this->closure->fresh()->execution_request_id);
        Http::assertNothingSent();
    }

    public function test_request_body_cannot_replace_member_uid_or_execution_request_id(): void
    {
        $this->postJson($this->path(), ['confirm' => true, 'member_id' => $this->operator->id, 'uid' => 'attacker', 'request_id' => 'attacker'])
            ->assertOk();
        $request = $this->closeRequests()[0][0]['data'];
        $this->assertSame('101', $request['user']['uid']);
        $this->assertTrue(Str::isUuid($request['request_id']));
        $this->assertNull($this->operator->fresh()->member_closed_at);
    }

    public function test_verification_before_execution_cannot_disable_the_member(): void
    {
        $this->postJson($this->path('verify'))->assertConflict();
        $this->assertNull($this->member->fresh()->member_closed_at);
        Http::assertNothingSent();
    }

    public function test_migration_rollback_and_reapply_preserve_existing_members_and_reviews(): void
    {
        $migration = require database_path('migrations/2026_11_24_000001_add_member_closure_execution.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'member_closed_at'));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('member_closure_requests', ['id' => $this->closure->id, 'status' => 'approved']);
        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', 'member_closed_at'));
        $this->assertSame('not_started', $this->closure->fresh()->execution_status);
    }

    public function test_numeric_uid_and_documented_inactive_case_are_supported(): void
    {
        $this->closeChanges = ['uid' => 101, 'status' => 'inactive'];
        $this->postJson($this->path(), ['confirm' => true])->assertOk()->assertJsonPath('data.execution_status', 'completed');
    }

    public function test_staff_and_dedicated_test_accounts_are_not_closed(): void
    {
        $this->closure->forceFill(['member_id' => $this->operator->id])->saveOrFail();
        $this->postJson($this->path(), ['confirm' => true])->assertNotFound();
        $this->closure->forceFill(['member_id' => $this->member->id])->saveOrFail();
        config(['yaband_remit.base_url' => 'https://remittestv2.yabandpay.com', 'remit.test_sms_login.enabled' => true,
            'remit.test_sms_login.mobile' => '+34600000000', 'remit.test_sms_login.code' => '123456']);
        $this->member->forceFill(['phone_e164' => '+34600000000', 'email' => app(TestSmsLogin::class)->memberEmail('+34600000000')])->saveOrFail();
        $this->postJson($this->path(), ['confirm' => true])->assertForbidden();
        $this->assertNull($this->member->fresh()->member_closed_at);
        Http::assertNothingSent();
    }

    public function test_sms_login_cannot_reopen_a_closed_phone_account(): void
    {
        $this->member->forceFill(['phone_e164' => '+34612345678'])->saveOrFail();
        $this->postJson($this->path(), ['confirm' => true])->assertOk();
        $channel = new class implements SmsChannel
        {
            public string $code = '';

            public function send(string $mobileE164, string $code, string $scene): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(SmsChannel::class, $channel);
        $this->app->instance('env', 'local');
        config(['remit.local_sms_login' => true]);
        $this->app['auth']->forgetGuards();
        $this->postJson('/app/auth/sms-code', ['mobile' => '+34612345678'])->assertOk();
        $this->assertNotSame('', $channel->code);
        $this->postJson('/app/auth/sms-login', ['mobile' => '+34612345678', 'code' => $channel->code])->assertForbidden();
        $this->assertSame(0, $this->member->tokens()->count());
        $this->assertDatabaseCount('users', 2);
    }

    #[DataProvider('invalidCloseResponses')]
    public function test_invalid_provider_response_never_disables_user_or_replays(array $changes): void
    {
        $this->closeChanges = $changes;
        $this->postJson($this->path(), ['confirm' => true])->assertServiceUnavailable();
        $this->assertSame('pending_confirmation', $this->closure->fresh()->execution_status);
        $requestId = $this->closure->fresh()->execution_request_id;
        $this->assertNull($this->member->fresh()->member_closed_at);
        $this->assertSame(1, $this->member->tokens()->count());
        $this->postJson($this->path(), ['confirm' => true])->assertConflict();
        $this->assertSame($requestId, $this->closure->fresh()->execution_request_id);
        $this->assertCount(1, $this->closeRequests());
    }

    public static function invalidCloseResponses(): array
    {
        return [[['uid' => 'another-user']], [['uid' => null]], [['mid' => 'another-mid']], [['mid' => null]],
            [['type' => 'transaction']], [['status' => 'Active']], [['status' => null]], [['status' => ['Inactive']]],
            [['user' => '{"uid":"another-user"}']]];
    }

    public function test_unknown_result_is_reconciled_by_query_without_repeating_close(): void
    {
        $this->timeout = true;
        $this->postJson($this->path(), ['confirm' => true])->assertServiceUnavailable();
        $requestId = $this->closure->fresh()->execution_request_id;
        $this->queriedStatus = '1001';
        $this->postJson($this->path('verify'))->assertConflict();
        $this->assertNull($this->member->fresh()->member_closed_at);
        $this->queriedStatus = '1002';
        $this->postJson($this->path('verify'))->assertOk()->assertJsonPath('data.execution_status', 'completed');
        $this->assertSame($requestId, $this->closure->fresh()->execution_request_id);
        $this->assertCount(1, $this->closeRequests());
        $this->assertNotNull($this->member->fresh()->member_closed_at);
        $this->assertNotNull($this->closure->fresh()->last_checked_at);
    }

    public function test_not_found_error_or_unknown_query_status_does_not_mean_closed(): void
    {
        $this->timeout = true;
        $this->postJson($this->path(), ['confirm' => true])->assertServiceUnavailable();
        $this->queryFails = true;
        $this->postJson($this->path('verify'))->assertServiceUnavailable();
        $this->assertNull($this->member->fresh()->member_closed_at);
        $this->queryFails = false;
        $this->queriedStatus = '1999';
        $this->postJson($this->path('verify'))->assertConflict();
        $this->assertNull($this->member->fresh()->member_closed_at);
        $this->assertCount(1, $this->closeRequests());
    }

    public function test_unregistered_member_can_be_closed_locally_without_contacting_provider(): void
    {
        app(YabandRemitMemberRepository::class)->findFor($this->member->id)->delete();
        $this->postJson($this->path(), ['confirm' => true])->assertOk()->assertJsonPath('data.execution_status', 'completed');
        $this->assertNotNull($this->member->fresh()->member_closed_at);
        Http::assertNothingSent();
    }

    public function test_changed_uid_binding_blocks_reconciliation(): void
    {
        $this->timeout = true;
        $this->postJson($this->path(), ['confirm' => true])->assertServiceUnavailable();
        $members = app(YabandRemitMemberRepository::class);
        $members->saveState($members->findFor($this->member->id), 'other-uid', null);
        $count = Http::recorded()->count();
        $this->postJson($this->path('verify'))->assertConflict();
        $this->assertSame($count, Http::recorded()->count());
        $this->assertNull($this->member->fresh()->member_closed_at);
    }

    public function test_order_appearing_during_close_keeps_local_account_pending_until_settled(): void
    {
        $this->orderAppears = true;
        $this->postJson($this->path(), ['confirm' => true])->assertConflict();
        $this->assertNull($this->member->fresh()->member_closed_at);
        $this->assertSame('pending_confirmation', $this->closure->fresh()->execution_status);
        RemittanceOrder::query()->where('member_id', $this->member->id)->update(['income_code' => '00', 'refund_code' => '04']);
        $this->postJson($this->path('verify'))->assertOk();
        $this->assertCount(1, $this->closeRequests());
    }

    public function test_kyc_member_lock_prevents_overlapping_close_and_new_kyc_mutations(): void
    {
        $lock = Cache::lock('yaband-remit:kyc:'.$this->member->id, 75);
        $this->assertTrue($lock->get());
        try {
            $this->postJson($this->path(), ['confirm' => true])->assertConflict();
        } finally {
            $lock->release();
        }
        $this->assertSame(409, app(MemberKycService::class)->start($this->member)->getCode());
        $this->assertSame(409, app(MemberKycService::class)->submitProfile($this->member, [])->getCode());
        Http::assertNothingSent();
    }

    private function path(string $action = 'execute'): string
    {
        return '/admin/remittance/closures/'.$this->closure->id.'/'.$action;
    }

    private function asUser(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken($user->is_platform_staff ? 'platform' : 'member', $user->is_platform_staff ? PlatformRoles::abilities($user) : [])->plainTextToken);
    }

    private function closeRequests(): array
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/kyc/close_account'))->values()->all();
    }

    private function unsettledOrder(): void
    {
        RemittanceOrder::query()->forceCreate(['reference' => (string) Str::uuid(), 'member_id' => $this->member->id,
            'provider_trade_id' => 'fixture-trade', 'principal_minor' => 10000, 'white_label' => false,
            'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00']);
    }
}
