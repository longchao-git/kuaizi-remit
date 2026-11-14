<?php

namespace Tests\Feature;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReconciliationAndOperationsTest extends TestCase
{
    use RefreshDatabase;

    private const RECON = '/api/admin/remittance/reconciliation';

    public function test_reconciliation_persists_amount_and_status_differences_and_reuses_equivalent_csv(): void
    {
        $finance = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'finance']);
        $operations = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $member = User::factory()->create();
        $matched = $this->order($member, '00', '05', '00');
        $amount = $this->order($member, '00', '05', '00');
        $status = $this->order($member, '00', '05', '04');
        $both = $this->order($member, '00', '05', '00');
        $unknown = (string) Str::uuid();
        $this->getJson(self::RECON)->assertUnauthorized();
        $this->login($operations);
        $this->getJson(self::RECON)->assertForbidden();
        $this->getJson('/api/user/menus')->assertJsonMissing(['component' => 'remittance/reconciliation']);
        $this->login($finance);
        $this->getJson('/api/user/menus')->assertJsonFragment(['component' => 'remittance/reconciliation']);
        $this->getJson('/api/admin/remittance/rules')->assertForbidden();
        $lines = [
            "$matched,100.01,paid_out", "$amount,100.00,paid_out", "$status,100.01,paid_out",
            "$both,99.99,paid", "$unknown,100.01,",
        ];
        $header = 'order_id,amount_eur,status';
        $batch = $this->upload("\xEF\xBB\xBF".$header."\r\n".implode("\r\n", $lines))
            ->assertOk()->assertJsonPath('data.reused', false)->json('data.id');
        $this->upload($header."\n".implode("\n", array_reverse($lines)))
            ->assertOk()->assertJsonPath('data.id', $batch)->assertJsonPath('data.reused', true);
        $this->getJson(self::RECON.'?settlement_date=2026-11-13')->assertJsonPath('data.total', 0);
        $this->getJson(self::RECON.'?order_reference='.$status.'&status=pending')->assertJsonPath('data.total', 1);
        $this->getJson(self::RECON.'?order_reference='.$status.'&status=reviewed')->assertJsonPath('data.total', 0);
        $this->getJson(self::RECON.'?order_reference='.(string) Str::uuid())->assertJsonPath('data.total', 0);
        $this->getJson(self::RECON.'?settlement_date=2026-11-14')->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.line_count', 5)->assertJsonPath('data.list.0.matched_count', 1)
            ->assertJsonPath('data.list.0.difference_count', 4)->assertJsonPath('data.list.0.pending_count', 4);
        $route = self::RECON.'/'.$batch.'/lines';
        $rows = collect($this->getJson($route)->assertOk()->json('data.list'))->keyBy('order_reference');
        $this->assertSame('matched', $rows[$matched]['result']);
        $this->assertSame('amount', $rows[$amount]['result']);
        $this->assertSame('status', $rows[$status]['result']);
        $this->assertSame('refunded', $rows[$status]['expected_status']);
        $this->assertSame('amount_status', $rows[$both]['result']);
        $this->assertSame('missing', $rows[$unknown]['result']);
        $this->assertSame(10000, $rows[$amount]['imported_minor']);
        $this->assertSame(10001, $rows[$amount]['expected_minor']);
        $this->putJson($route.'/'.$rows[$matched]['id'], ['note' => '无差异'])->assertConflict();
        $this->putJson(self::RECON.'/999/lines/'.$rows[$amount]['id'], ['note' => '差异已查'])->assertNotFound();
        $this->putJson($route.'/'.$rows[$amount]['id'], ['note' => '=2+2'])->assertOk()
            ->assertJsonPath('data.reviewed_by', (string) $finance->id)->assertJsonPath('data.result', 'amount');
        $this->putJson($route.'/'.$rows[$amount]['id'], ['note' => '重复复核'])->assertConflict();
        $this->getJson($route.'?filter=pending')->assertOk()->assertJsonPath('data.total', 3);
        $this->getJson($route.'?filter=differences')->assertOk()->assertJsonPath('data.total', 4);
        $this->getJson(self::RECON)->assertJsonPath('data.list.0.pending_count', 3);
        $export = $this->get(self::RECON.'/'.$batch.'/export')->assertOk()->assertDownload('reconciliation-'.$batch.'.csv');
        $this->assertStringContainsString("'=2+2", $export->streamedContent());
        $this->assertDatabaseHas('remittance_orders', ['reference' => $amount, 'principal_minor' => 10001, 'outgoing_code' => '05']);

        config(['remit.platform_admin_emails' => [$operations->email]]);
        $this->login($operations);
        $this->getJson('/api/admin/remittance/audit?action=reconciliation_imported')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.reference_id', $batch);
        $this->getJson('/api/admin/remittance/audit?action=reconciliation_reviewed')->assertOk()
            ->assertJsonPath('data.total', 1)->assertJsonPath('data.list.0.operator_id', (string) $finance->id);
    }

    public function test_invalid_reconciliation_file_does_not_write_partial_batches(): void
    {
        $this->login(User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'finance']));
        $id = (string) Str::uuid();
        $this->upload("order_id,amount_eur\n$id,10\nunknown,10")->assertUnprocessable()
            ->assertJsonPath('msg', '第 3 行的订单号、金额或状态格式有误');
        $this->upload("order_id,amount_eur\n$id,10\n".strtoupper($id).',20')->assertUnprocessable()
            ->assertJsonPath('msg', '第 3 行的订单号重复');
        $this->upload("order_id,amount_eur\n$id,10.001")->assertUnprocessable();
        $this->upload("order_id,amount_eur\n$id,\"10")->assertUnprocessable();
        $this->upload("order_id,amount_eur,status\n$id,100,06")->assertUnprocessable();
        $this->upload("order_id,amount_eur\n")->assertUnprocessable();
        $this->assertDatabaseCount('remittance_reconciliation_batches', 0);
        $this->assertDatabaseCount('remittance_reconciliation_lines', 0);
    }

    public function test_operations_share_quote_rules_preserve_fee_and_eta_and_require_current_revision(): void
    {
        $staff = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'operations']);
        $member = User::factory()->create();
        $this->login($staff);
        $base = '/api/admin/remittance';
        $eta = ['threshold_minor' => 100_000, 'below' => '低档', 'at_or_above' => '高档'];
        $rules = [
            'base_fee_minor' => 599, 'first_order_free' => false,
            'payment' => ['sepa' => ['basis_points' => 0], 'applePay' => ['basis_points' => 80], 'card' => ['basis_points' => 100]],
            'eta' => ['wechat' => $eta, 'bank' => $eta],
        ];
        $first = $this->putJson($base.'/rules', [...$rules, 'revision_id' => '0'])->assertOk()->json('data.revision_id');
        $notice = ['notice' => '通道费 {费率}', 'notice_enabled' => true];
        $input = [
            'revision_id' => $first, 'announcement' => ['enabled' => true, 'text' => '首页公告'],
            'payment' => ['sepa' => ['notice' => null, 'notice_enabled' => false], 'applePay' => $notice, 'card' => $notice],
        ];
        $this->putJson($base.'/operations', [...$input, 'announcement' => ['enabled' => true, 'text' => null]])->assertUnprocessable();
        $this->putJson($base.'/operations', [...$input, 'payment' => [...$input['payment'], 'applePay' => [...$notice, 'basis_points' => 500]]])->assertUnprocessable();
        $second = $this->putJson($base.'/operations', $input)->assertOk()->assertJsonPath('data.payment.applePay.basis_points', 80)
            ->assertJsonPath('data.eta.bank.at_or_above', '高档')->json('data.revision_id');
        $this->putJson($base.'/operations', $input)->assertConflict();
        $this->putJson($base.'/rules', [...$rules, 'revision_id' => $first])->assertConflict();
        $this->getJson($base.'/rules/revisions')->assertJsonPath('data.list.0.settings.announcement.text', '首页公告');

        $quote = ['principal_minor' => 100_000, 'rate_ten_thousandths' => 80_540, 'payment_method' => 'applePay', 'payout_method' => 'bank'];
        $this->postJson($base.'/quote-preview', $quote)->assertOk()->assertJsonPath('data.payment_notice', '通道费 0.8%')
            ->assertJsonPath('data.channel_fee_minor', 800)->assertJsonPath('data.eta', '高档');
        $this->login($member);
        $this->getJson('/api/app/remittance/announcement')->assertOk()->assertJsonPath('data.announcement', '首页公告')
            ->assertJsonMissingPath('data.payment');
        $this->putJson($base.'/operations', $input)->assertForbidden();
        $this->login($staff);
        $rules['payment']['applePay']['basis_points'] = 90;
        $rules['payment']['applePay']['notice'] = '通道费 {费率}';
        $third = $this->putJson($base.'/rules', [...$rules, 'revision_id' => $second])->assertOk()
            ->assertJsonPath('data.announcement.text', '首页公告')->json('data.revision_id');
        $this->postJson($base.'/quote-preview', $quote)->assertJsonPath('data.payment_notice', '通道费 0.9%');
        $this->putJson($base.'/operations', [...$input, 'revision_id' => $third, 'announcement' => ['enabled' => false, 'text' => '首页公告']])->assertOk();
        $this->login($member);
        $this->getJson('/api/app/remittance/announcement')->assertOk()->assertJsonPath('data.announcement', null);
    }

    private function order(User $member, string $income, string $outgoing, string $refund): string
    {
        $id = (string) Str::uuid();
        DB::table('remittance_orders')->insert([
            'reference' => $id, 'member_id' => $member->id, 'white_label' => false,
            'principal_minor' => 10001, 'income_code' => $income, 'outgoing_code' => $outgoing,
            'refund_code' => $refund, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function upload(string $csv)
    {
        return $this->post(self::RECON, [
            'settlement_date' => '2026-11-14',
            'file' => UploadedFile::fake()->createWithContent('settlement.csv', $csv),
        ], ['Accept' => 'application/json']);
    }

    private function login(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($user->createToken($user->is_platform_staff ? 'platform' : 'member', PlatformRoles::abilities($user))->plainTextToken);
    }
}
