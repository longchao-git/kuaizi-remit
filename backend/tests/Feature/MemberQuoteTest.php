<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\RemittancePermission;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use App\Repository\Remittance\RemittanceRuleRepository;
use App\Service\Remittance\RemittanceRuleService;
use App\Service\Remittance\Yaband\Contract\YabandRateLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemberQuoteTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/app/remittance/quotes';

    private const INPUT = ['principal_minor' => 100_000, 'payment_method' => 'card', 'payout_method' => 'bank'];

    public function test_locked_rate_is_persisted_only_from_a_server_adapter_and_is_not_a_draft(): void
    {
        $this->freezeTime();
        $this->app->make(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599, 'first_order_free' => false,
            'payment' => ['card' => ['basis_points' => 80]],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $member = User::factory()->create();
        $this->withToken($member->createToken('member')->plainTextToken);
        $this->app->instance(YabandRateLock::class, new class implements YabandRateLock
        {
            public function lock(int $member, array $request): array
            {
                return ['reference' => 'upstream-lock', 'expires_at' => now()->addMinutes(2)->timestamp, 'rate_ten_thousandths' => 80540];
            }
        });
        $record = $this->postJson(self::PATH, self::INPUT)->assertOk()->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.mode', 'locked')->assertJsonPath('data.expires_at', now()->addMinutes(2)->timestamp)
            ->assertJsonMissingPath('data.provider_lock_reference')->json('data.record_no');
        $this->putJson('/api/app/remittance/drafts/'.$record)->assertNotFound();
        $saved = app(RemittanceQuoteRecordRepository::class)->findByReference($record);
        $this->assertSame('upstream-lock', $saved->snapshot['provider_lock_reference']);
    }

    public function test_quote_requires_a_member_token_and_fails_closed_without_test_configuration(): void
    {
        $this->postJson(self::PATH, self::INPUT)->assertUnauthorized();
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $this->withToken($staff->createToken('platform')->plainTextToken)->postJson(self::PATH, self::INPUT)->assertForbidden();
        $this->app['auth']->forgetGuards();

        $this->withToken(User::factory()->create()->createToken('member')->plainTextToken);
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $this->postJson(self::PATH, self::INPUT)->assertServiceUnavailable()->assertJsonPath('code', 50301);
        $this->app->instance('env', 'local');
        $this->postJson(self::PATH, self::INPUT)->assertServiceUnavailable()->assertJsonPath('msg', '汇款规则尚未配置。');
    }

    public function test_member_can_save_and_remove_only_own_quote_drafts(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $this->app->make(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599,
            'first_order_free' => false,
            'payment' => ['card' => ['basis_points' => 80]],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $member = User::factory()->create()->createToken('member')->plainTextToken;
        $other = User::factory()->create()->createToken('member')->plainTextToken;
        $url = '/api/app/remittance/drafts';
        $this->withToken($member)->getJson($url)->assertJsonPath('data.total', 0);
        $reference = $this->postJson(self::PATH, self::INPUT)->assertOk()->json('data.record_no');
        $saved = $this->putJson($url.'/'.$reference)->assertOk()->assertJsonPath('data.principal_minor', 100_000)
            ->assertJsonMissingPath('data.rate_ten_thousandths')->json('data.draft_saved_at');
        $this->travel(1)->minutes();
        $this->putJson($url.'/'.$reference)->assertJsonPath('data.draft_saved_at', $saved);

        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson($url)->assertJsonPath('data.total', 0);
        $this->putJson($url.'/'.$reference)->assertNotFound();
        $this->postJson($url.'/'.$reference.'/remove')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($member)->getJson($url)->assertJsonPath('data.list.0.record_no', $reference);
        $this->postJson($url.'/'.$reference.'/remove')->assertOk();
        $this->getJson($url)->assertJsonPath('data.total', 0);
        $this->assertDatabaseHas('remittance_quote_records', ['reference' => $reference, 'draft_saved_at' => null]);
    }

    public function test_quote_uses_server_rules_and_is_explicitly_non_payable(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $this->app->make(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 599,
            'first_order_free' => true,
            'payment' => ['card' => ['basis_points' => 80, 'notice' => '费率 {费率}']],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $this->withToken(User::factory()->create()->createToken('member')->plainTextToken);

        $this->postJson(self::PATH, [...self::INPUT, 'rate_ten_thousandths' => 123, 'simulate_first_order' => true])
            ->assertUnprocessable();
        $this->postJson(self::PATH, self::INPUT)->assertOk()
            ->assertJsonPath('data.rate_ten_thousandths', 80_540)
            ->assertJsonPath('data.payout_minor', 805_400)
            ->assertJsonPath('data.discount_minor', 0)
            ->assertJsonPath('data.channel_fee_minor', 800)
            ->assertJsonPath('data.payment_notice', '费率 0.8%')
            ->assertJsonPath('data.eta', '标准')
            ->assertJsonPath('data.total_payable_minor', 101_399)
            ->assertJsonPath('data.mode', 'local_estimate')
            ->assertJsonPath('data.can_submit', false)
            ->assertJsonMissingPath('data.quote_id');
    }

    public function test_saved_estimates_keep_their_original_rules_and_can_only_be_searched_by_authorized_staff(): void
    {
        $this->app->instance('env', 'local');
        config(['remit.local_rate_ten_thousandths' => '80540']);
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $member = User::factory()->create();
        $rules = [
            'base_fee_minor' => 599,
            'first_order_free' => false,
            'payment' => ['card' => ['basis_points' => 80, 'notice' => '费率 {费率}']],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ];
        $service = $this->app->make(RemittanceRuleService::class);
        $service->save($rules, $staff);
        $list = '/api/admin/remittance/quote-records';
        $this->getJson($list)->assertUnauthorized();
        $this->withToken($member->createToken('member')->plainTextToken);
        $first = $this->postJson(self::PATH, self::INPUT)->assertOk()->json('data');
        $this->getJson($list)->assertForbidden();

        $service->save([...$rules, 'base_fee_minor' => 200, 'spread_basis_points' => 50], $staff);
        $second = $this->postJson(self::PATH, self::INPUT)->assertOk()->json('data');
        $this->assertNotSame($first['record_no'], $second['record_no']);
        $this->assertNotSame($first['rule_revision_id'], $second['rule_revision_id']);
        $this->assertSame(80137, $second['rate_ten_thousandths']);
        $this->assertArrayNotHasKey('source_rate_ten_thousandths', $second);
        $this->assertArrayNotHasKey('spread_basis_points', $second);
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('platform', [RemittancePermission::SUPPORT])->plainTextToken)
            ->getJson($list)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($staff->createToken('platform', [RemittancePermission::MANAGE_RULES])->plainTextToken);
        $this->getJson($list.'?member_id='.$member->id)->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.record_no', $second['record_no'])
            ->assertJsonPath('data.list.0.source_rate_ten_thousandths', 80540)
            ->assertJsonPath('data.list.0.spread_basis_points', 50);
        $this->getJson($list.'?record_no='.strtoupper($first['record_no']))
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.rule_revision_id', $first['rule_revision_id'])
            ->assertJsonPath('data.list.0.base_fee_minor', 599)
            ->assertJsonPath('data.list.0.can_submit', false);
        $this->getJson($list.'?record_no=bad')->assertUnprocessable();
        $margin = '/api/admin/remittance/margin-preview';
        $input = ['record_no' => $first['record_no'], 'fx_income_minor' => -200, 'cost_minor' => 1600];
        $this->postJson($margin, $input)->assertOk()
            ->assertJsonPath('data.fee_income_minor', 1399)->assertJsonPath('data.gross_margin_minor', -401)
            ->assertJsonPath('data.gross_margin_percent_of_principal', -0.4);
        $this->postJson($margin, [...$input, 'cost_minor' => -1])->assertUnprocessable();
        $this->postJson($margin, [...$input, 'record_no' => 'f39b3e30-2f31-43d7-96cf-2bf4664b20ed'])->assertNotFound();
    }
}
