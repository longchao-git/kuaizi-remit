<?php

namespace Tests\Feature;

use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Repository\Remittance\RemittanceRuleRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\RemittanceChannels;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\YabandRemitHmacSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class LocalRemittanceChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_complete_simulated_kyc_checkout_and_refund_without_any_external_request(): void
    {
        config(['remit.channel' => 'local', 'remit.local_rate_ten_thousandths' => 80540,
            'yaband_remit.reference_rate_enabled' => true, 'broadcasting.default' => 'log']);
        Http::fake();
        app(RemittanceRuleRepository::class)->save('quote', [
            'base_fee_minor' => 100, 'first_order_free' => false,
            'payment' => ['card' => ['basis_points' => 80]],
            'eta' => ['bank' => ['threshold_minor' => 100_000, 'below' => '较快', 'at_or_above' => '标准']],
        ]);
        $member = User::factory()->create();
        $this->withToken($member->createToken('member')->plainTextToken);
        $this->postJson('/app/kyc/idv')->assertOk()->assertJsonPath('data.simulated', true)->assertJsonMissingPath('data.url');
        $this->getJson('/app/kyc/status')->assertJsonPath('data.can_submit_profile', true);
        $this->postJson('/api/app/kyc/profile', [
            'first_name' => 'Fixture', 'last_name' => 'Test', 'nationality' => 'ES', 'id_type' => 'passport', 'id_number' => 'SYNTHETIC',
            'expiry_date' => now()->addYear()->toDateString(), 'birthday' => '1990-01-01', 'gender' => 'F',
            'address' => ['country' => 'ES', 'address_line1' => 'Fixture Street', 'city' => 'Test', 'post_code' => '00000'],
        ])->assertOk()->assertJsonPath('data.kyc_passed', true);
        $account = $this->getJson('/app/remittance/payout-accounts?payout_method=bank')->assertOk()->json('data.0.reference');
        $quote = $this->postJson('/app/remittance/quotes', ['principal_minor' => 10000, 'payment_method' => 'card', 'payout_method' => 'bank'])
            ->assertOk()->assertJsonPath('data.simulated', true)->assertJsonPath('data.can_submit', true)->json('data.record_no');
        $payload = ['quote_reference' => $quote, 'payout_account' => $account, 'declaration' => ['source_of_funds' => '01', 'purpose' => '01']];
        $this->postJson('/app/remittance/orders', [...$payload, 'payout_account' => (string) Str::uuid()])->assertServiceUnavailable();
        $this->assertDatabaseCount('remittance_orders', 0);
        $order = $this->postJson('/app/remittance/orders', $payload)->assertOk()->assertJsonPath('data.payment_url', null)->json('data.reference');
        $this->postJson('/app/remittance/orders', $payload)->assertOk()->assertJsonPath('data.reference', $order);
        $this->assertDatabaseHas('remittance_orders', ['reference' => $order, 'channel_scope' => 'local', 'provider_trade_id' => 'sim-'.$order]);

        $path = '/app/remittance/orders/'.$order.'/refresh';
        config(['remit.local_scenario' => 'delivered']);
        $this->postJson($path)->assertOk()->assertJsonPath('data.simulated', true)->assertJsonPath('data.outgoing_code', '05');
        config(['remit.local_scenario' => 'refunded']);
        $this->postJson($path)->assertOk()->assertJsonPath('data.refund_code', '04');
        config(['remit.channel' => 'yaband', 'remit.local_scenario' => 'pending']);
        $this->postJson('/app/remittance/orders', $payload)->assertConflict();
        $this->postJson($path)->assertConflict();
        $this->assertDatabaseCount('remittance_orders', 1);
        $this->assertDatabaseHas('remittance_orders', ['reference' => $order, 'refund_code' => '04']);
        Http::assertNothingSent();
    }

    public function test_changed_yaband_merchant_and_local_mode_cannot_reuse_real_identity_or_order(): void
    {
        Http::fake();
        config(['yaband_remit.base_url' => 'https://sandbox.example.test', 'yaband_remit.mid' => 'original', 'yaband_remit.password' => 'fixture-password']);
        $this->app->instance(YabandRemitSigner::class, new YabandRemitHmacSigner('fixture-secret'));
        $scope = app(RemittanceChannels::class)->yabandScope();
        $member = User::factory()->create();
        $links = app(YabandRemitMemberRepository::class);
        $links->saveState($links->getOrCreate($member->id, $scope), 'original-uid', null);
        $order = RemittanceOrder::query()->forceCreate([
            'reference' => (string) Str::uuid(), 'member_id' => $member->id, 'channel_scope' => $scope,
            'provider_trade_id' => 'original-trade', 'principal_minor' => 10000, 'white_label' => true,
            'income_code' => '03', 'outgoing_code' => '01', 'refund_code' => '00',
        ]);
        $this->withToken($member->createToken('member')->plainTextToken);
        config(['yaband_remit.mid' => 'changed']);
        $this->postJson('/app/remittance/orders/'.$order->reference.'/refresh')->assertServiceUnavailable();
        config(['remit.channel' => 'local']);
        $this->postJson('/app/kyc/idv')->assertConflict();
        $this->postJson('/app/remittance/orders/'.$order->reference.'/refresh')->assertServiceUnavailable();
        $this->app->instance('env', 'production');
        $this->postJson('/app/remittance/quotes', ['principal_minor' => 10000, 'payment_method' => 'card', 'payout_method' => 'bank'])
            ->assertServiceUnavailable();
        Http::assertNothingSent();
    }
}
