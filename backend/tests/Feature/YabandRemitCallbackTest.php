<?php

namespace Tests\Feature;

use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Providers\AppServiceProvider;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class YabandRemitCallbackTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private RemittanceOrder $order;

    private int $couponId;

    private array $kyc;

    private array $transaction;

    private bool $offline = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'broadcasting.default' => 'log', 'cache.default' => 'array',
            'yaband_remit.base_url' => 'https://callback.example.test', 'yaband_remit.mid' => 'fixture-mid',
            'yaband_remit.password' => 'fixture-password', 'yaband_remit.secret_key' => 'fixture-key',
            'yaband_remit.callback_verification_enabled' => true,
        ]);
        $this->app->register(AppServiceProvider::class, true);
        $this->member = User::factory()->create();
        $members = app(YabandRemitMemberRepository::class);
        $members->saveState($members->getOrCreate($this->member->id), '101', null);
        $this->order = RemittanceOrder::query()->forceCreate([
            'reference' => (string) Str::uuid(), 'member_id' => $this->member->id,
            'provider_trade_id' => 'fixture-trade', 'principal_minor' => 10000, 'white_label' => false,
            'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00',
        ]);
        $coupon = app(RemittanceCouponRepository::class)->issue((string) Str::uuid(), [
            'member_id' => $this->member->id, 'issue_fingerprint' => 'callback-fixture',
            'title' => '测试券', 'discount_bps' => 3000, 'expires_at' => now()->addDay(),
        ]);
        $coupon->forceFill(['order_reference' => $this->order->reference])->saveOrFail();
        $this->couponId = $coupon->id;
        $this->kyc = ['mid' => 'fixture-mid', 'type' => 'kyc', 'uid' => '101',
            'user_status_code' => '1001', 'kyc_status_code' => '5004', 'idv_status_code' => '2006',
            'profile_status_code' => '3003', 'remit_status_code' => '4001'];
        $this->transaction = ['mid' => 'fixture-mid', 'uid' => '101',
            'order_id' => $this->order->reference, 'trade_id' => 'fixture-trade', 'amount' => '100.00', 'currency' => 'EUR',
            'income_status_code' => '00', 'outgoing_status_code' => '04', 'refund_status_code' => '00'];
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/api/auth/login') {
                $data = ['token' => 'fixture-token', 'token_type' => 'Bearer', 'expires_in' => 600, 'expires_at' => now()->addMinutes(10)->timestamp];
            } else {
                if ($this->offline) {
                    return Http::failedConnection();
                }
                $data = match ($path) {
                    '/api/kyc/query' => $this->kyc,
                    '/api/transaction/query' => ['transaction_info' => $this->transaction],
                    default => throw new RuntimeException('Unexpected callback HTTP path'),
                };
            }

            return Http::response(['status' => true, 'code' => 200, 'data' => $data]);
        });
    }

    public function test_verification_requires_explicit_switch_and_valid_configuration(): void
    {
        $this->app->offsetUnset(YabandRemitCallbackVerifier::class);
        config(['yaband_remit.callback_verification_enabled' => false]);
        $this->app->register(AppServiceProvider::class, true);
        $this->assertFalse($this->app->bound(YabandRemitCallbackVerifier::class));
        $this->postJson('/callbacks/yaband/remit/kyc', $this->notice($this->kycNotice()))->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    #[DataProvider('missingConfiguration')]
    public function test_incomplete_configuration_keeps_callback_closed(string $key): void
    {
        $this->app->offsetUnset(YabandRemitCallbackVerifier::class);
        config(['yaband_remit.'.$key => '']);
        $this->app->register(AppServiceProvider::class, true);
        $this->assertFalse($this->app->bound(YabandRemitCallbackVerifier::class));
        $this->postJson('/callbacks/yaband/remit/kyc', $this->notice($this->kycNotice()))->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public static function missingConfiguration(): array
    {
        return [['secret_key'], ['mid']];
    }

    public function test_connection_check_reports_enabled_verifier_without_showing_credentials(): void
    {
        $this->artisan('remit:check-yaband')
            ->expectsOutput('回调验签：已注册')
            ->doesntExpectOutputToContain('fixture-key')
            ->doesntExpectOutputToContain('fixture-password')
            ->assertSuccessful();
        Http::assertSentCount(1);
    }

    #[DataProvider('callbackForms')]
    public function test_kyc_notices_verify_and_requery_without_duplicate_messages(bool $wrapped, bool $userObject, string $prefix): void
    {
        $data = $this->kycNotice();
        if ($userObject) {
            $data['user'] = ['uid' => 101, 'name' => '测/试', 'nil' => null];
        }
        $body = $this->notice($data, $wrapped);
        $path = $prefix.'/callbacks/yaband/remit/kyc';
        $this->postJson($path, $body)->assertOk()->assertContent('ok')->assertHeader('Content-Type', 'text/plain; charset=utf-8');
        $this->postJson($path, $body)->assertOk()->assertContent('ok');
        $this->assertDatabaseCount('member_notifications', 1);
        $this->assertTrue(app(YabandRemitMemberRepository::class)->findFor($this->member->id)->statuses['remittance_enabled']);
        $this->assertCount(2, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/kyc/query')));
        $this->assertDatabaseHas('remittance_orders', ['reference' => $this->order->reference, 'income_code' => '03']);
    }

    public static function callbackForms(): iterable
    {
        foreach ([true, false] as $wrapped) {
            foreach ([true, false] as $object) {
                foreach (['', '/api'] as $prefix) {
                    yield ($wrapped ? 'wrapped' : 'flat').'-'.($object ? 'object' : 'string').'-'.($prefix === '' ? 'root' : 'legacy') => [$wrapped, $object, $prefix];
                }
            }
        }
    }

    public function test_tampering_wrong_merchant_or_event_is_rejected_before_any_external_request(): void
    {
        $body = $this->notice($this->kycNotice());
        $body['data']['user'] = '{"uid":"another-user"}';
        $this->postJson('/callbacks/yaband/remit/kyc', $body)->assertForbidden();
        $this->postJson('/callbacks/yaband/remit/kyc', $this->notice([...$this->kycNotice(), 'mid' => 'another-mid']))->assertForbidden();
        $this->postJson('/callbacks/yaband/remit/transaction', $this->notice($this->kycNotice()))->assertForbidden();
        Http::assertNothingSent();
        $this->assertUnchanged();
    }

    public function test_valid_signatures_for_unknown_bindings_cannot_create_members_or_transactions(): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->notice([...$this->kycNotice(), 'user' => '{"uid":"unknown"}']))->assertNotFound();
        $data = [...$this->transaction, 'type' => 'transaction', 'trade_id' => 'unbound-trade'];
        $this->postJson('/callbacks/yaband/remit/transaction', $this->notice($data))->assertNotFound();
        Http::assertNothingSent();
        $this->assertDatabaseCount('yaband_remit_members', 1);
        $this->assertDatabaseCount('remittance_orders', 1);
        $this->assertUnchanged();
    }

    public function test_valid_order_notice_requeries_and_retries_do_not_redeem_or_notify_twice(): void
    {
        $body = $this->notice([...$this->transaction, 'type' => 'transaction']);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/callbacks/yaband/remit/transaction', $body)->assertOk()->assertContent('ok');
        }
        $this->assertDatabaseHas('remittance_orders', ['reference' => $this->order->reference, 'income_code' => '00', 'outgoing_code' => '04']);
        $this->assertNotNull($this->order->fresh()->provider_checked_at);
        $this->assertDatabaseCount('member_notifications', 1);
        $this->assertDatabaseMissing('remittance_coupons', ['id' => $this->couponId, 'redeemed_at' => null]);
        $this->assertCount(3, Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/transaction/query')));
    }

    public function test_callback_claims_cannot_override_authoritative_query_states(): void
    {
        $this->kyc['remit_status_code'] = '4002';
        $this->postJson('/callbacks/yaband/remit/kyc', $this->notice($this->kycNotice()))->assertOk();
        $this->assertFalse(app(YabandRemitMemberRepository::class)->findFor($this->member->id)->statuses['remittance_enabled']);
        $this->transaction['income_status_code'] = '03';
        $claimed = [...$this->transaction, 'type' => 'transaction', 'income_status_code' => '00', 'refund_status_code' => '04', 'amount' => '999.99'];
        $this->postJson('/callbacks/yaband/remit/transaction', $this->notice($claimed))->assertOk();
        $this->assertDatabaseHas('remittance_orders', ['reference' => $this->order->reference, 'income_code' => '03', 'refund_code' => '00']);
        $this->assertDatabaseHas('remittance_coupons', ['id' => $this->couponId, 'redeemed_at' => null]);
    }

    public function test_failed_query_is_not_acknowledged_and_same_signed_notice_can_retry(): void
    {
        $this->offline = true;
        $body = $this->notice([...$this->transaction, 'type' => 'transaction']);
        $this->postJson('/callbacks/yaband/remit/transaction', $body)->assertServiceUnavailable();
        $this->assertUnchanged();
        $this->offline = false;
        $this->postJson('/callbacks/yaband/remit/transaction', $body)->assertOk()->assertContent('ok');
    }

    public function test_foreign_query_response_is_not_saved_after_valid_notification(): void
    {
        $this->transaction['uid'] = 'another-user';
        $body = $this->notice([...$this->transaction, 'type' => 'transaction']);
        $this->postJson('/callbacks/yaband/remit/transaction', $body)->assertStatus(502);
        $this->assertUnchanged();
    }

    #[DataProvider('invalidUsers')]
    public function test_signed_but_invalid_user_fields_do_not_trigger_queries(mixed $user): void
    {
        $this->postJson('/callbacks/yaband/remit/kyc', $this->notice([...$this->kycNotice(), 'user' => $user]))->assertStatus(422);
        Http::assertNothingSent();
        $this->assertUnchanged();
    }

    public static function invalidUsers(): array
    {
        return [[null], ['{'], ['[]'], [['uid' => true]], [['uid' => []]], [['uid' => '']], [['uid' => str_repeat('a', 65)]], [['uid' => 1.25]]];
    }

    public function test_malformed_non_json_and_oversized_bodies_are_rejected(): void
    {
        $this->post('/callbacks/yaband/remit/kyc', ['sign' => 'x'])->assertStatus(415);
        $this->call('POST', '/callbacks/yaband/remit/kyc', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{')->assertStatus(422);
        $this->call('POST', '/callbacks/yaband/remit/kyc', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat(' ', 262145))->assertStatus(413);
        Http::assertNothingSent();
        $this->assertUnchanged();
    }

    private function kycNotice(): array
    {
        return ['type' => 'kyc', 'mid' => 'fixture-mid', 'user' => ' { "uid":101, "images":"[]" } ', 'remit_status_code' => '4001'];
    }

    private function notice(array $data, bool $wrapped = true): array
    {
        $sorted = $data;
        ksort($sorted);
        $pairs = [];
        foreach ($sorted as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            $pairs[] = $key.'='.(string) $value;
        }
        $sign = hash_hmac('sha256', implode('&', $pairs), 'fixture-key');

        return $wrapped ? ['sign' => $sign, 'data' => $data] : [...$data, 'sign' => $sign];
    }

    private function assertUnchanged(): void
    {
        $this->assertDatabaseHas('remittance_orders', ['reference' => $this->order->reference, 'income_code' => '03', 'provider_checked_at' => null]);
        $this->assertDatabaseHas('remittance_coupons', ['id' => $this->couponId, 'redeemed_at' => null]);
        $this->assertDatabaseCount('member_notifications', 0);
    }
}
