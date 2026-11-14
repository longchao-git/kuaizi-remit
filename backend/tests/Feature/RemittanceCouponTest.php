<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\RemittancePermission;
use App\Repository\Remittance\RemittanceRuleRepository;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RemittanceCouponTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuance_claim_discount_and_revocation_enforce_coupon_ownership(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $this->app->make(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599,
            'first_order_free' => true,
            'payment' => ['card' => ['basis_points' => 80]],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $staffToken = $staff->createToken('platform', [RemittancePermission::MANAGE_COUPONS])->plainTextToken;
        $member = User::factory()->create()->createToken('app')->plainTextToken;
        $other = User::factory()->create()->createToken('app')->plainTextToken;
        $path = '/api/admin/remittance/coupons';
        $input = ['request_id' => (string) Str::uuid(), 'title' => '手续费半价', 'discount_bps' => 5000, 'valid_days' => 1];
        $quote = ['principal_minor' => 100_000, 'payment_method' => 'card', 'payout_method' => 'bank'];

        $this->withToken($member)->postJson($path, $input)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $issued = $this->withToken($staffToken)->postJson($path, $input)->assertOk()->json('data');
        $this->assertSame('discount', $issued['coupon_type']);
        $this->postJson($path, $input)->assertJsonPath('data.id', $issued['id']);
        $this->postJson($path, [...$input, 'valid_days' => 2])->assertConflict();
        $this->postJson($path, [...$input, 'request_id' => (string) Str::uuid(), 'member_id' => $staff->id])->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($member)->postJson('/api/app/coupons/claim', ['code' => strtolower($issued['code'])])
            ->assertOk()->assertJsonPath('data.id', $issued['id'])->assertJsonMissingPath('data.code');
        $this->postJson('/api/app/coupons/claim', ['code' => $issued['code']])->assertJsonPath('data.id', $issued['id']);
        $quote['coupon_id'] = (int) $issued['id'];
        $this->postJson('/api/app/remittance/quotes', $quote)->assertOk()
            ->assertJsonPath('data.discount_minor', 300)->assertJsonPath('data.channel_fee_minor', 800)
            ->assertJsonPath('data.total_payable_minor', 101099)->assertJsonPath('data.can_submit', false);
        $this->getJson('/api/app/coupons')->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.status', 'available');

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->postJson('/api/app/coupons/claim', ['code' => $issued['code']])->assertConflict();
        $this->postJson('/api/app/remittance/quotes', $quote)->assertConflict();
        $this->getJson('/api/app/coupons')->assertJsonPath('data.total', 0);

        $this->app['auth']->forgetGuards();
        $this->withToken($staffToken)->postJson($path.'/'.$issued['id'].'/revoke')
            ->assertOk()->assertJsonPath('data.revoked_by', $staff->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($member)->postJson('/api/app/remittance/quotes', $quote)->assertConflict();
        $this->getJson('/api/app/coupons')->assertJsonPath('data.list.0.status', 'revoked');
    }

    public function test_direct_issue_has_no_code_and_expiry_blocks_use(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $member = User::factory()->create();
        $path = '/api/admin/remittance/coupons';
        $this->withToken($staff->createToken('platform', [RemittancePermission::MANAGE_COUPONS])->plainTextToken)
            ->postJson($path, ['request_id' => (string) Str::uuid(), 'title' => '免手续费', 'member_id' => $member->id, 'discount_bps' => 10000, 'valid_days' => 1])
            ->assertOk()->assertJsonPath('data.code', null)->assertJsonPath('data.member_id', (string) $member->id);
        $this->travel(2)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('app')->plainTextToken)->getJson('/api/app/coupons')
            ->assertJsonPath('data.list.0.status', 'expired');
    }

    public function test_first_order_coupon_waives_only_the_service_fee_and_requires_first_order_eligibility(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        app(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599, 'first_order_free' => false,
            'payment' => ['card' => ['basis_points' => 80]],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $member = User::factory()->create();
        $issued = $this->withToken($staff->createToken('platform', [RemittancePermission::MANAGE_COUPONS])->plainTextToken)
            ->postJson('/api/admin/remittance/coupons', [
                'request_id' => (string) Str::uuid(), 'title' => '新人首单', 'member_id' => $member->id,
                'discount_bps' => 10000, 'valid_days' => 1,
            ])->assertJsonPath('data.coupon_type', 'first_order_free')->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('app')->plainTextToken);
        $quote = ['principal_minor' => 100_000, 'payment_method' => 'card', 'payout_method' => 'bank', 'coupon_id' => (int) $issued];
        $path = '/api/app/remittance/quotes';
        $this->postJson($path, $quote)->assertOk()->assertJsonPath('data.discount_minor', 599)->assertJsonPath('data.total_payable_minor', 100800);

        DB::table('remittance_orders')->insert([
            'reference' => (string) Str::uuid(), 'member_id' => $member->id, 'white_label' => false,
            'principal_minor' => 10000, 'income_code' => '00', 'outgoing_code' => '05', 'refund_code' => '00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->postJson($path, $quote)->assertConflict()->assertJsonPath('msg', '新人首单券仅适用于首笔汇款，请检查已有订单');
    }

    public function test_home_offer_requires_recipient_claim_before_quote_and_retries_are_idempotent(): void
    {
        Event::fake([AnonymousEvent::class]);
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $this->app->make(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599,
            'first_order_free' => false,
            'payment' => ['sepa' => ['basis_points' => 0]],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $member = User::factory()->create();
        $other = User::factory()->create();
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $staffToken = $staff->createToken('platform', [RemittancePermission::MANAGE_COUPONS])->plainTextToken;
        $memberToken = $member->createToken('app')->plainTextToken;
        $staffPath = '/api/admin/remittance/coupons';
        $wallet = '/api/app/coupons';
        $input = [
            'request_id' => (string) Str::uuid(),
            'delivery' => 'home_popup',
            'title' => '首页专享',
            'discount_bps' => 5000,
            'valid_days' => 1,
        ];

        $this->withToken($staffToken)->postJson($staffPath, $input)->assertUnprocessable();
        $this->postJson($staffPath, [...$input, 'delivery' => 'code', 'member_id' => $member->id])->assertUnprocessable();
        $draft = [...$input, 'member_id' => $member->id];
        $id = $this->postJson($staffPath, $draft)
            ->assertOk()->assertJsonPath('data.status', 'pending_claim')->assertJsonPath('data.code', null)
            ->assertJsonPath('data.delivery', 'home_popup')->json('data.id');
        $this->postJson($staffPath, $draft)->assertJsonPath('data.id', $id);
        $this->postJson($staffPath, [...$draft, 'delivery' => null])->assertUnprocessable();
        Event::assertDispatchedTimes(AnonymousEvent::class, 1);

        $claim = $wallet.'/'.$id.'/claim';
        $quote = ['coupon_id' => (int) $id, 'principal_minor' => 100_000, 'payment_method' => 'sepa', 'payout_method' => 'bank'];
        $this->app['auth']->forgetGuards();
        $this->withToken($other->createToken('app')->plainTextToken)->getJson($wallet.'/home-offer')->assertJsonPath('data.offer', null);
        $this->postJson($claim)->assertConflict();

        $this->app['auth']->forgetGuards();
        $this->withToken($memberToken)->getJson($wallet.'/home-offer')->assertJsonPath('data.offer.id', $id);
        $this->getJson($wallet)->assertJsonPath('data.list.0.status', 'pending_claim');
        $this->postJson('/api/app/remittance/quotes', $quote)->assertConflict();
        $this->postJson($claim)->assertOk()->assertJsonPath('data.status', 'available');
        $firstClaim = DB::table('remittance_coupons')->where('id', $id)->value('claimed_at');
        $this->travel(1)->minutes();
        $this->postJson($claim)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertDatabaseHas('remittance_coupons', ['id' => $id, 'claimed_at' => $firstClaim]);
        $this->getJson($wallet.'/home-offer')->assertJsonPath('data.offer', null);
        $this->postJson('/api/app/remittance/quotes', $quote)->assertJsonPath('data.discount_minor', 300);

        $this->app['auth']->forgetGuards();
        $second = $this->withToken($staffToken)->postJson($staffPath, [...$draft, 'request_id' => (string) Str::uuid()])
            ->assertOk()->json('data.id');
        $this->postJson($staffPath.'/'.$second.'/revoke')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($memberToken)->getJson($wallet.'/home-offer')->assertJsonPath('data.offer', null);
        $this->postJson($wallet.'/'.$second.'/claim')->assertConflict();
        $this->travel(2)->days();
        $this->postJson($claim)->assertConflict();
    }

    public function test_template_scope_is_frozen_at_issue_and_disabled_template_cannot_issue_again(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $eta = ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准'];
        $this->app->make(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599,
            'first_order_free' => true,
            'payment' => ['card' => ['basis_points' => 80]],
            'eta' => ['bank' => $eta, 'wechat' => $eta],
        ]);
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $member = User::factory()->create();
        $path = '/api/admin/remittance/coupons';
        $templates = $path.'/templates';
        $draft = ['title' => '银行卡限用', 'discount_bps' => 5000, 'valid_days' => 7, 'payout_method' => 'bank', 'enabled' => true];
        $this->withToken($staff->createToken('platform', [RemittancePermission::MANAGE_COUPONS])->plainTextToken);
        $templateId = $this->postJson($templates, $draft)->assertOk()->json('data.id');
        $issue = ['request_id' => (string) Str::uuid(), 'template_id' => (int) $templateId, 'member_id' => $member->id];
        $id = $this->postJson($path, $issue)->assertOk()
            ->assertJsonPath('data.title', $draft['title'])->assertJsonPath('data.payout_method', 'bank')->json('data.id');
        $this->postJson($path, [...$issue, 'discount_bps' => 1])->assertUnprocessable();
        $this->putJson($templates.'/'.$templateId, [...$draft, 'discount_bps' => 1000, 'payout_method' => 'wechat', 'enabled' => false])
            ->assertOk();
        $this->getJson($templates.'?enabled_only=1')->assertJsonPath('data.total', 0);
        $this->postJson($path, $issue)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson($path, [...$issue, 'request_id' => (string) Str::uuid()])->assertConflict();

        $this->app['auth']->forgetGuards();
        $this->withToken($member->createToken('app')->plainTextToken)->getJson('/api/app/coupons')
            ->assertJsonPath('data.list.0.payout_method', 'bank');
        $quote = ['coupon_id' => (int) $id, 'principal_minor' => 100_000, 'payment_method' => 'card'];
        $this->postJson('/api/app/remittance/quotes', [...$quote, 'payout_method' => 'wechat'])->assertConflict();
        $this->postJson('/api/app/remittance/quotes', [...$quote, 'payout_method' => 'bank'])->assertOk()
            ->assertJsonPath('data.discount_minor', 300);
    }
}
