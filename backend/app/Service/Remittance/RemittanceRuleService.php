<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\RemittanceRule;
use App\Model\Remittance\RemittanceRuleRevision;
use App\Model\System\User;
use App\Repository\Remittance\RemittanceRuleRepository;
use App\Repository\Remittance\RemittanceRuleRevisionRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

#[Singleton]
final class RemittanceRuleService
{
    /** 负责当前规则、保存记录和报价试算。 */
    public function __construct(
        private RemittanceRuleRepository $rules,
        private RemittanceRuleRevisionRepository $revisions,
        private DatabaseManager $database,
        private QuoteCalculator $calculator,
    ) {}

    /** 查询当前生效的付款及到账规则。 */
    public function get(): Result
    {
        $rule = $this->rules->findByKey('quote');

        return $rule === null ? Result::fail('请先配置汇款规则', 409) : Result::success([
            ...$rule->settings, 'revision_id' => $this->revisionId($rule),
        ]);
    }

    /** 同一事务保存规则和版本快照，确保配置与审计一起生效。 */
    public function save(array $settings, User $staff, ?string $revision = null): Result
    {
        foreach ($settings['eta'] as $channel => $rule) {
            $bands = $rule['bands'] ?? [];
            usort($bands, static fn (array $left, array $right) => $left['from_minor'] <=> $right['from_minor']);
            $end = 0;
            foreach ($bands as $band) {
                if ($band['until_minor'] !== null && $band['until_minor'] <= $band['from_minor']) {
                    return Result::fail('预计到账区间的上限必须大于下限', 422);
                }
                if (! $band['enabled']) {
                    continue;
                }
                if ($band['from_minor'] < $end) {
                    return Result::fail('同一收款渠道的启用区间不能重叠', 422);
                }
                $end = $band['until_minor'] ?? PHP_INT_MAX;
            }
            if (array_key_exists('bands', $rule)) {
                $settings['eta'][$channel]['bands'] = $bands;
            }
        }

        return $this->database->transaction(function () use ($settings, $staff, $revision): Result {
            $current = $this->rules->findByKey('quote', lock: true);
            if ($revision !== null && $revision !== $this->revisionId($current)) {
                return Result::fail('规则已更新，请刷新后重试', 409);
            }
            if (isset($current->settings['announcement'])) {
                $settings['announcement'] = $current->settings['announcement'];
            }

            return $this->persist($settings, $staff, '已保存汇款规则');
        });
    }

    /** 只允许修改展示文案与开关，继续使用现行费率和到账区间。 */
    public function saveOperations(array $input, User $staff): Result
    {
        return $this->database->transaction(function () use ($input, $staff): Result {
            $current = $this->rules->findByKey('quote', lock: true);
            if ($current === null) {
                return Result::fail('请先配置汇款规则', 409);
            }
            if ($input['revision_id'] !== $this->revisionId($current)) {
                return Result::fail('规则已更新，请刷新后重试', 409);
            }
            $settings = $current->settings;
            foreach ($input['payment'] as $method => $notice) {
                $settings['payment'][$method] = [...$settings['payment'][$method], ...$notice];
            }
            $settings['announcement'] = $input['announcement'];

            return $this->persist($settings, $staff, '已保存运营配置');
        });
    }

    public function memberAnnouncement(): Result
    {
        $announcement = $this->rules->settings('quote')['announcement'] ?? [];
        $text = ($announcement['enabled'] ?? false) ? ($announcement['text'] ?? null) : null;

        return Result::success(['announcement' => $text]);
    }

    private function persist(array $settings, User $staff, string $message): Result
    {
        $revision = $this->revisions->append((int) $staff->getKey(), $staff->name, $settings);
        $this->rules->save('quote', $settings, $revision);

        return Result::success([...$settings, 'revision_id' => (string) $revision], $message);
    }

    private function revisionId(?RemittanceRule $rule): string
    {
        return (string) ($rule?->revision_id ?? 0);
    }

    /** 查询员工保存规则的记录及当时的配置。 */
    public function revisions(int $page): Result
    {
        $rows = $this->revisions->page($page);
        $items = array_map(static fn (RemittanceRuleRevision $row) => [
            'id' => (string) $row->id,
            'staff_name' => $row->staff_name,
            'settings' => $row->settings,
            'created_at' => $row->created_at->toISOString(),
        ], $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    /** 按运营输入试算，支持模拟首单免基础费。 */
    public function preview(array $input): Result
    {
        return $this->calculate($input, $input['rate_ten_thousandths'], $input['simulate_first_order'] ?? false);
    }

    /** 使用服务器汇率与运营规则计算联调报价，不模拟优惠核销。 */
    public function calculate(array $input, int $rate, bool $simulateFirstOrder = false, int $discountBps = 0): Result
    {
        $rule = $this->rules->findByKey('quote');
        if ($rule === null) {
            return Result::fail('请先配置汇款规则', 409);
        }

        $settings = $rule->settings;
        $spread = $settings['spread_basis_points'] ?? 0;
        $customerRate = intdiv($rate * (10_000 - $spread) + 5_000, 10_000);
        if ($customerRate < 1) {
            return Result::fail('点差调整后的汇率无效，请检查点差设置。', 409);
        }
        $payment = $settings['payment'][$input['payment_method']];
        $eta = $settings['eta'][$input['payout_method']];
        $discount = $settings['first_order_free'] && $simulateFirstOrder
            ? $settings['base_fee_minor']
            : intdiv($settings['base_fee_minor'] * $discountBps + 5_000, 10_000);

        $quote = $this->calculator->calculate(
            $input['principal_minor'],
            $customerRate,
            $settings['base_fee_minor'],
            $discount,
            $payment['basis_points'],
            $eta['threshold_minor'],
            $eta['below'],
            $eta['at_or_above'],
            ($payment['notice_enabled'] ?? true) ? ($payment['notice'] ?? '') : '',
        );

        foreach ($eta['bands'] ?? [] as $band) {
            if ($band['enabled'] && $input['principal_minor'] >= $band['from_minor']
                && ($band['until_minor'] === null || $input['principal_minor'] < $band['until_minor'])) {
                $quote['eta'] = $band['label'];
                break;
            }
        }

        return Result::success([
            ...$quote,
            'source_rate_ten_thousandths' => $rate,
            'spread_basis_points' => $spread,
            'rule_revision_id' => $rule->revision_id === null ? null : (string) $rule->revision_id,
        ]);
    }
}
