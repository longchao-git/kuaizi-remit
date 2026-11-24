<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\RemittancePermission;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\Contract\YabandCheckoutTerms;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InstitutionPaymentFixture;
use Tests\TestCase;

final class YabandRemitWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['yaband_remit.base_url' => 'https://sandbox.example.test', 'yaband_remit.mid' => 'test-mid', 'yaband_remit.password' => 'test-pass']);
        Http::preventStrayRequests();
        Http::fake(['*/api/auth/login' => Http::response($this->reply([
            'token' => 'test-token', 'token_type' => 'bearer', 'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp,
        ]))]);
    }

    public function test_material_attachment_goes_to_the_registered_question_and_identical_retry_reuses_request_id(): void
    {
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $operator = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->link($member);
        $this->asToken($operator, 'platform', [RemittancePermission::MANAGE_MATERIALS]);
        $input = ['member_id' => $member->id, 'question_id' => 'fixture-qno', 'instruction' => '补交资金来源', 'deadline_at' => now()->addHour()->timestamp];
        $task = $this->postJson('/api/admin/remittance/materials', $input)->assertOk()->json('data.reference');
        $this->postJson('/api/admin/remittance/materials', $input)->assertJsonPath('data.reference', $task);
        $this->postJson('/api/admin/remittance/materials', [...$input, 'instruction' => '不同要求'])->assertConflict();
        $this->getJson('/api/admin/remittance/materials')->assertJsonPath('data.total', 1);

        $this->asToken($stranger);
        $this->getJson('/api/app/remittance/materials')->assertJsonPath('data.total', 0);
        $submit = '/api/app/remittance/materials/'.$task;
        $this->postJson($submit, ['reply' => '已补资料'])->assertNotFound();
        $this->asToken($member);
        $this->getJson('/api/app/remittance/materials')->assertJsonPath('data.list.0.reference', $task)
            ->assertJsonMissingPath('data.list.0.question_id');
        $this->postJson($submit, ['reply' => '已补资料'])->assertServiceUnavailable();
        Http::assertNothingSent();

        $this->bindSigner();
        Http::fake(['*/api/materials/update' => Http::sequence()->pushStatus(503)
            ->push($this->reply(['qno' => 'fixture-qno', 'qno_status_code' => '6002']))]);
        $pdf = "%PDF-1.4\nfixture";
        $fields = fn () => ['reply' => '已补资料', 'document' => UploadedFile::fake()->createWithContent('receipt.pdf', $pdf)];
        $this->post($submit, $fields(), ['Accept' => 'application/json'])->assertServiceUnavailable();
        $this->postJson($submit, ['reply' => '变更回复'])->assertConflict();
        $this->post($submit, $fields(), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', '6002');
        $this->post($submit, $fields(), ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', '6002');
        $sends = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/materials/update'))->values();
        $this->assertCount(2, $sends);
        $first = $sends[0][0]['data'];
        $this->assertSame($first, $sends[1][0]['data']);
        $this->assertTrue(Str::isUuid($first['request_id']));
        $this->assertSame('fixture-qno', $first['qno']);
        $this->assertSame(['doc_id' => $first['request_id'], 'media_type' => 'pdf', 'attachment' => base64_encode($pdf)], $first['document'][0]);
    }

    public function test_material_task_closes_on_upstream_expiration_or_completion_without_confirming_submission(): void
    {
        $member = User::factory()->create();
        $operator = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->link($member);
        $this->bindSigner();
        Http::fake(['*/api/materials/update' => Http::sequence()
            ->push(['status' => false, 'code' => -1032, 'message' => 'Question expired'])
            ->push(['status' => false, 'code' => '-1033', 'message' => 'Question completed'])]);

        foreach (['6003' => '雅本补件任务已过期', '6002' => '雅本补件任务已完成，请刷新查看'] as $status => $message) {
            $this->asToken($operator, 'platform', [RemittancePermission::MANAGE_MATERIALS]);
            $reference = $this->postJson('/api/admin/remittance/materials', [
                'member_id' => $member->id,
                'question_id' => 'fixture-qno-'.$status,
                'instruction' => '补交资金来源',
                'deadline_at' => now()->addHour()->timestamp,
            ])->assertOk()->json('data.reference');

            $this->asToken($member);
            $submit = '/api/app/remittance/materials/'.$reference;
            $fields = ['reply' => '已补资料'];
            $this->postJson($submit, $fields)->assertConflict()->assertJsonPath('msg', $message);
            $tasks = $this->getJson('/api/app/remittance/materials')->assertOk()->json('data.list');
            $task = collect($tasks)->firstWhere('reference', $reference);
            $this->assertSame((string) $status, $task['status']);
            $this->assertFalse($task['can_submit']);
            $this->postJson($submit, $fields)->assertConflict();
        }

        $sends = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/api/materials/update'));
        $this->assertCount(2, $sends);
    }

    public function test_kyc_notice_keeps_signed_nested_string_and_notifies_only_on_state_change(): void
    {
        $member = User::factory()->create();
        $this->link($member);
        $userJson = '{ "uid": 101, "images":"[]" }';
        $notice = ['sign' => 'expected-signature', 'data' => ['mid' => 'test-mid', 'type' => 'kyc', 'user' => $userJson, 'idv_report' => null]];
        $path = '/api/callbacks/yaband/remit/kyc';
        $this->postJson($path, $notice)->assertServiceUnavailable();
        $this->app->instance(YabandRemitCallbackVerifier::class, new class implements YabandRemitCallbackVerifier
        {
            public function verifies(string $event, string $body, array $payload): bool
            {
                return $event === 'kyc' && $payload['sign'] === 'expected-signature'
                    && $payload['data']['user'] === '{ "uid": 101, "images":"[]" }'
                    && json_decode($body, true) === $payload;
            }
        });
        $this->postJson($path, [...$notice, 'sign' => 'wrong'])->assertForbidden();
        $this->postJson($path, [...$notice, 'data' => [...$notice['data'], 'mid' => 'other']])->assertForbidden();
        Http::assertNothingSent();
        $this->bindSigner();

        $passed = ['uid' => 101, 'user_status_code' => '1001', 'remit_status_code' => '4001', 'kyc_status_code' => '5004', 'idv_status_code' => '2006'];
        $expired = ['uid' => 101, 'user_status_code' => '1001', 'remit_status_code' => '4002', 'kyc_status_code' => '5003', 'idv_status_code' => '2002'];
        Http::fake(['*/api/kyc/query' => Http::sequence()->push($this->reply($passed))->push($this->reply($passed))
            ->push($this->reply($expired))->push($this->reply($expired))]);
        for ($i = 0; $i < 4; $i++) {
            $this->postJson($path, $notice)->assertOk()->assertContent('ok');
        }
        $this->assertDatabaseCount('member_notifications', 2);
        $this->assertDatabaseHas('member_notifications', ['member_id' => $member->id, 'title' => '请更新身份认证资料', 'sender_id' => null]);
        $status = app(YabandRemitMemberRepository::class)->findFor($member->id)->statuses;
        $this->assertTrue($status['can_start_idv']);
        $this->assertFalse($status['remittance_enabled']);
    }

    public function test_tickets_can_only_be_linked_to_a_members_own_order_and_cannot_change_on_retry(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $reference = (string) Str::uuid();
        DB::table('remittance_orders')->insert([
            'reference' => $reference, 'member_id' => $owner->id, 'provider_trade_id' => 'fixture-trade',
            'white_label' => true, 'principal_minor' => 10000, 'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $path = '/api/app/support/tickets';
        $input = ['client_id' => (string) Str::uuid(), 'subject' => '订单查询', 'body' => '查询我的订单', 'order_reference' => $reference];
        $this->asToken($stranger);
        $this->postJson($path, $input)->assertNotFound();
        $this->asToken($owner);
        $id = $this->postJson($path, $input)->assertJsonPath('data.order_reference', $reference)->json('data.id');
        $this->postJson($path, $input)->assertJsonPath('data.id', $id);
        $this->postJson($path, [...$input, 'order_reference' => null])->assertConflict();
        $this->getJson($path.'/'.$id)->assertJsonPath('data.order_reference', $reference);
        $this->assertDatabaseCount('support_tickets', 1);
    }

    public function test_checkout_requires_a_locked_quote_and_reuses_the_saved_order_and_request_after_a_timeout(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($member->id), '101', ['kyc_passed' => true, 'remittance_enabled' => true]);
        $quotes = app(RemittanceQuoteRecordRepository::class);
        $coupons = app(RemittanceCouponRepository::class);
        $firstCoupon = $coupons->issue((string) Str::uuid(), [
            'member_id' => $member->id, 'issue_fingerprint' => 'first-coupon', 'title' => '首单免手续费',
            'discount_bps' => 10000, 'expires_at' => now()->addDay(),
        ]);
        $base = ['rule_revision_id' => null, 'principal_minor' => 10000, 'payout_minor' => 80540, 'expires_at' => now()->addMinutes(10)->timestamp];
        $estimate = $quotes->record($member->id, $base + ['mode' => 'local_estimate', 'can_submit' => false]);
        $locked = $quotes->record($member->id, $base + ['mode' => 'locked', 'can_submit' => true, 'coupon_id' => $firstCoupon->id]);
        $anotherQuote = $quotes->record($member->id, $base + ['mode' => 'locked', 'can_submit' => true]);
        $path = '/api/app/remittance/orders';
        $input = ['quote_reference' => $locked->reference, 'payout_account' => (string) Str::uuid()];
        $this->asToken($other);
        $this->postJson($path, $input)->assertNotFound();
        $this->asToken($member);
        $this->postJson($path, [...$input, 'quote_reference' => $estimate->reference])->assertConflict();
        $this->postJson($path, $input)->assertServiceUnavailable();
        $this->assertDatabaseCount('remittance_orders', 0);
        Http::assertNothingSent();

        $terms = new class implements YabandCheckoutTerms
        {
            public bool $replaySafe = false;

            public function accounts(User $member, string $payoutMethod): array
            {
                return [['reference' => 'b2199430-56db-4c16-bba4-0a1df2ff30eb', 'label' => '银行卡 · 8888', 'secret' => 'hidden']];
            }

            public function prepare(User $member, array $snapshot, string $payoutAccount, string $ip): array
            {
                $fields = InstitutionPaymentFixture::fields();
                $fields['payee']['account'] = $payoutAccount;
                $fields['payee'] = json_encode($fields['payee'], JSON_THROW_ON_ERROR);
                $fields['amount'] = '1.00';

                return ['white_label' => true, 'replay_safe' => $this->replaySafe, 'fields' => $fields];
            }
        };
        $this->app->instance(YabandCheckoutTerms::class, $terms);
        $this->getJson('/api/app/remittance/payout-accounts?payout_method=bank')
            ->assertJsonPath('data.0.label', '银行卡 · 8888')->assertJsonMissingPath('data.0.secret');
        $this->bindSigner();
        $attempts = 0;
        Http::fake(['*/api/kyc/query' => Http::response($this->reply([
            'uid' => '101', 'user_status_code' => '1001', 'remit_status_code' => '4001',
            'kyc_status_code' => '5004', 'idv_status_code' => '2006', 'profile_status_code' => '3003',
        ])), '*/api/whitelable/transaction/payment' => function (Request $request) use (&$attempts) {
            if (++$attempts === 1) {
                return Http::response([], 504);
            }

            return Http::response($this->reply([
                'order_id' => $request['data']['order_id'], 'trade_id' => 'trade-101', 'url' => 'https://sandbox.example.test/pay',
            ]));
        }]);
        $this->postJson($path, $input)->assertServiceUnavailable();
        $this->assertDatabaseCount('remittance_orders', 1);
        $reserved = DB::table('remittance_orders')->first();
        $this->assertNull($reserved->provider_trade_id);
        $this->getJson('/api/app/coupons')->assertJsonPath('data.list.0.status', 'reserved')
            ->assertJsonPath('data.list.0.coupon_type', 'first_order_free');
        $this->postJson($path, [...$input, 'quote_reference' => $anotherQuote->reference])->assertConflict();
        $this->postJson($path, [...$input, 'payout_account' => (string) Str::uuid()])->assertConflict();
        $this->postJson($path, $input)->assertConflict();
        $this->assertSame(1, $attempts);
        $terms->replaySafe = true;
        $this->postJson($path, $input)->assertJsonPath('data.reference', $reserved->reference)
            ->assertJsonPath('data.payment_url', 'https://sandbox.example.test/pay');
        $this->postJson($path, $input)->assertJsonPath('data.reference', $reserved->reference);
        $this->getJson($path.'/'.$reserved->reference)->assertJsonPath('data.quote.payout_minor', 80540)
            ->assertJsonPath('data.payment_url', 'https://sandbox.example.test/pay');
        $sent = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/transaction/payment'))->values();
        $this->assertCount(2, $sent);
        $this->assertSame($sent[0][0]['data'], $sent[1][0]['data']);
        $this->assertSame($reserved->payment_request_id, $sent[1][0]['data']['request_id']);
        $this->assertSame('100.00', $sent[1][0]['data']['amount']);
        $this->assertDatabaseCount('remittance_orders', 1);
        $this->travel(11)->minutes();
        $this->getJson($path.'/'.$reserved->reference)->assertJsonPath('data.payment_url', null);
        $this->travelBack();
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $this->asToken($staff, 'platform', [RemittancePermission::SYNC_ORDERS]);
        $state = [
            'order_id' => $reserved->reference, 'trade_id' => 'trade-101',
            'income_status_code' => '00', 'outgoing_status_code' => '04', 'refund_status_code' => '00',
        ];
        Http::fake(['*/api/whitelable/transaction/query' => Http::sequence()
            ->push($this->reply(['transaction_info' => $state]))
            ->push($this->reply(['transaction_info' => [...$state, 'refund_status_code' => '04']]))]);
        $refresh = '/api/admin/remittance/orders/'.$reserved->reference.'/refresh';
        $this->postJson($refresh)->assertOk();
        $firstCoupon->refresh();
        $redeemedAt = $firstCoupon->redeemed_at->toISOString();
        $this->postJson($refresh)->assertOk();
        $this->assertSame($redeemedAt, $firstCoupon->fresh()->redeemed_at->toISOString());
        $this->asToken($member);
        $this->getJson('/api/app/coupons')->assertJsonPath('data.list.0.status', 'redeemed');
        $this->travel(1)->minutes();
        $closure = $this->postJson('/api/app/account/closures')->assertOk()->json('data.id');
        $this->asToken($staff, 'platform', [RemittancePermission::MANAGE_CLOSURES]);
        $this->putJson('/api/admin/remittance/closures/'.$closure, ['decision' => 'approved', 'note' => '退款已完成'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->asToken($member);
        $this->postJson($path, [...$input, 'quote_reference' => $anotherQuote->reference])->assertConflict()
            ->assertJsonPath('msg', '账号注销申请已通过审核，暂不能创建新汇款订单');
        $this->assertDatabaseCount('remittance_orders', 1);
    }

    private function asToken(User $user, string $name = 'member', array $abilities = []): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken($name, $abilities)->plainTextToken);
    }

    private function link(User $member): void
    {
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($member->id), '101', null);
    }

    private function bindSigner(): void
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
