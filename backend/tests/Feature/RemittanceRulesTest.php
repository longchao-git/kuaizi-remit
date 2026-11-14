<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\RemittancePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemittanceRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_anonymous_and_member_access_are_denied(): void
    {
        $this->getJson('/api/admin/remittance/rules')->assertUnauthorized();

        $member = User::factory()->create();
        $token = $member->createToken('member', [RemittancePermission::MANAGE_RULES]);
        $this->withToken($token->plainTextToken)->getJson('/api/admin/remittance/rules')->assertForbidden();
    }

    public function test_staff_can_save_rules_and_quote_uses_the_saved_channels_and_amount_band(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $token = $staff->createToken('platform', [RemittancePermission::MANAGE_RULES]);
        $this->withToken($token->plainTextToken);

        $rules = [
            'base_fee_minor' => 599,
            'first_order_free' => true,
            'payment' => [
                'sepa' => ['basis_points' => 0],
                'applePay' => ['basis_points' => 80, 'notice' => '通道费 {费率}'],
                'card' => ['basis_points' => 95],
            ],
            'eta' => [
                'wechat' => ['threshold_minor' => 100_000, 'below' => '微信低档', 'at_or_above' => '微信高档'],
                'bank' => ['threshold_minor' => 200_000, 'below' => '银行低档', 'at_or_above' => '银行高档'],
            ],
        ];
        $this->putJson('/api/admin/remittance/rules', $rules)->assertOk()->assertJsonPath('code', 0);
        $this->getJson('/api/admin/remittance/rules')->assertJsonPath('data.payment.card.basis_points', 95);
        $history = '/api/admin/remittance/rules/revisions';
        $this->getJson($history)->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.staff_name', $staff->name)
            ->assertJsonPath('data.list.0.settings.payment.card.basis_points', 95);

        $this->postJson('/api/admin/remittance/quote-preview', [
            'principal_minor' => 100_000,
            'rate_ten_thousandths' => 80_540,
            'payment_method' => 'applePay',
            'payout_method' => 'wechat',
            'simulate_first_order' => true,
        ])->assertOk()
            ->assertJsonPath('data.eta', '微信高档')
            ->assertJsonPath('data.payment_notice', '通道费 0.8%')
            ->assertJsonPath('data.total_payable_minor', 100_800);

        $rules['payment']['card']['basis_points'] = 10_001;
        $this->putJson('/api/admin/remittance/rules', $rules)->assertUnprocessable();
        $this->getJson('/api/admin/remittance/rules')->assertJsonPath('data.payment.card.basis_points', 95);
        $this->getJson($history)->assertJsonPath('data.total', 1);
        $rules['payment']['card']['basis_points'] = 90;
        $this->putJson('/api/admin/remittance/rules', $rules)->assertJsonPath('code', 0);
        $this->getJson($history)->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.settings.payment.card.basis_points', 90)
            ->assertJsonPath('data.list.1.settings.payment.card.basis_points', 95);
    }

    public function test_disabling_a_payment_notice_keeps_channel_fees_and_eta(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $this->withToken($staff->createToken('platform', [RemittancePermission::MANAGE_RULES])->plainTextToken);
        $eta = ['threshold_minor' => 100_000, 'below' => '低档', 'at_or_above' => '高档'];
        $rules = [
            'base_fee_minor' => 599,
            'first_order_free' => false,
            'payment' => [
                'sepa' => ['basis_points' => 0],
                'applePay' => ['basis_points' => 80, 'notice' => '通道费 {费率}', 'notice_enabled' => false],
                'card' => ['basis_points' => 95, 'notice' => '通道费 {费率}'],
            ],
            'eta' => ['wechat' => $eta, 'bank' => $eta],
        ];
        $quote = ['principal_minor' => 100_000, 'rate_ten_thousandths' => 80_540, 'payout_method' => 'bank'];

        $this->putJson('/api/admin/remittance/rules', $rules)->assertOk();
        $this->postJson('/api/admin/remittance/quote-preview', [...$quote, 'payment_method' => 'applePay'])
            ->assertOk()->assertJsonPath('data.payment_notice', '')->assertJsonPath('data.channel_fee_minor', 800)
            ->assertJsonPath('data.eta', '高档');
        $this->postJson('/api/admin/remittance/quote-preview', [...$quote, 'payment_method' => 'card'])
            ->assertOk()->assertJsonPath('data.payment_notice', '通道费 0.95%');
        $this->getJson('/api/admin/remittance/rules')->assertJsonPath('data.payment.applePay.notice', '通道费 {费率}');
    }

    public function test_eta_bands_match_by_channel_and_boundaries_with_default_fallback_and_reject_overlap(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true]);
        $this->withToken($staff->createToken('platform', [RemittancePermission::MANAGE_RULES])->plainTextToken);
        $standard = ['threshold_minor' => 50_000, 'below' => '默认低档', 'at_or_above' => '默认高档'];
        $rules = [
            'base_fee_minor' => 599,
            'first_order_free' => false,
            'payment' => array_fill_keys(['sepa', 'applePay', 'card'], ['basis_points' => 0]),
            'eta' => ['wechat' => [...$standard, 'bands' => [
                ['from_minor' => 30_000, 'until_minor' => null, 'label' => '无限上档', 'enabled' => true],
                ['from_minor' => 10_000, 'until_minor' => 20_000, 'label' => '首档', 'enabled' => true],
                ['from_minor' => 10_000, 'until_minor' => 30_000, 'label' => '停用档', 'enabled' => false],
            ]], 'bank' => $standard],
        ];
        $url = '/api/admin/remittance/rules';
        $this->putJson($url, $rules)->assertOk()->assertJsonPath('data.eta.wechat.bands.0.from_minor', 10_000);
        $quote = ['rate_ten_thousandths' => 80_540, 'payment_method' => 'sepa'];
        foreach ([
            [10_000, 'wechat', '首档'],
            [19_999, 'wechat', '首档'],
            [20_000, 'wechat', '默认低档'],
            [30_000, 'wechat', '无限上档'],
            [80_000, 'wechat', '无限上档'],
            [10_000, 'bank', '默认低档'],
        ] as [$amount, $channel, $expected]) {
            $this->postJson('/api/admin/remittance/quote-preview', [...$quote, 'principal_minor' => $amount, 'payout_method' => $channel])
                ->assertOk()->assertJsonPath('data.eta', $expected);
        }
        $rules['eta']['wechat']['bands'][0]['from_minor'] = 19_999;
        $this->putJson($url, $rules)->assertUnprocessable()->assertJsonPath('code', 422);
        $this->getJson($url.'/revisions')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.settings.eta.wechat.bands.2.from_minor', 30_000);
    }
}
