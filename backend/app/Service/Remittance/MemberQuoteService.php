<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Constants\Common\ErrorCode;
use App\Model\Remittance\RemittanceCoupon;
use App\Model\Remittance\RemittanceQuoteRecord;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandReferenceRateService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\LockTimeoutException;

/** App 正式与联调报价。本地试算只用于展示。 */
#[Singleton]
final class MemberQuoteService
{
    /** 复用后台已保存的费用和 ETA 规则。 */
    public function __construct(
        private RemittanceRuleService $rules,
        private RemittanceCouponRepository $coupons,
        private RemittanceOrderRepository $orders,
        private RemittanceQuoteRecordRepository $records,
        private YabandReferenceRateService $referenceRates,
        private RemittanceChannels $channels,
    ) {}

    /** 按所选渠道报价，并保存费用和实际锁汇状态。 */
    public function estimate(array $input, int $member): Result
    {
        $coupon = null;
        if (isset($input['coupon_id'])) {
            $coupon = $this->coupons->ownedActive($input['coupon_id'], $member);
            if ($coupon === null) {
                return Result::fail('该优惠券不可用，请重新选择', 409);
            }
            if ($coupon->payout_method !== null && $coupon->payout_method !== $input['payout_method']) {
                return Result::fail('该优惠券不适用于所选收款渠道', 409);
            }
            if ($coupon->discount_bps === RemittanceCoupon::FIRST_ORDER_BPS && $this->orders->hasPaidOrPending($member)) {
                return Result::fail('新人首单券仅适用于首笔汇款，请检查已有订单', 409);
            }
        }

        try {
            $scope = $this->channels->activeScope();
            $locked = $this->channels->rateLock($scope)?->lock($member, $input);
        } catch (YabandException) {
            return Result::fail('当前汇款渠道无法锁定汇率，请核对配置或稍后再试。', ErrorCode::QUOTE_UNAVAILABLE);
        }
        if ($locked !== null) {
            if (! is_string($locked['reference'] ?? null) || trim($locked['reference']) === '' || strlen($locked['reference']) > 200
                || ! is_int($locked['expires_at'] ?? null) || $locked['expires_at'] <= now()->timestamp
                || ! is_int($locked['rate_ten_thousandths'] ?? null)) {
                return Result::fail('锁汇结果无效，请重新报价。', ErrorCode::QUOTE_UNAVAILABLE);
            }
        }
        $referenceRate = null;
        if ($locked === null && config('yaband_remit.reference_rate_enabled') === true) {
            try {
                $referenceRate = $this->referenceRates->current();
            } catch (YabandException|LockTimeoutException) {
                return Result::fail('当前无法获取汇率，请稍后再试。', ErrorCode::QUOTE_UNAVAILABLE);
            }
        }
        $rate = filter_var($locked['rate_ten_thousandths'] ?? $referenceRate['rate_ten_thousandths'] ?? config('remit.local_rate_ten_thousandths'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1_000_000],
        ]);
        if (($locked === null && $referenceRate === null && ! app()->isLocal()) || $rate === false) {
            return Result::fail('当前无法获取汇率，请稍后再试。', ErrorCode::QUOTE_UNAVAILABLE);
        }

        $quote = $this->rules->calculate($input, $rate, discountBps: $coupon?->discount_bps ?? 0);
        if ($quote->isFail()) {
            $message = $quote->getMsg() === '请先配置汇款规则' ? '汇款规则尚未配置。' : '当前无法获取有效汇率，请稍后再试。';

            return Result::fail($message, ErrorCode::QUOTE_UNAVAILABLE);
        }

        $record = $this->records->record($member, [
            ...$quote->getData(),
            'payment_method' => $input['payment_method'],
            'payout_method' => $input['payout_method'],
            'mode' => $locked === null ? 'local_estimate' : 'locked',
            'can_submit' => $locked !== null,
            'coupon_id' => $coupon?->id,
            ...($referenceRate === null ? [] : ['rate_source' => 'yaband_reference', 'reference_rate_observed_at' => $referenceRate['observed_at']]),
            ...($locked === null ? [] : ['provider_lock_reference' => $locked['reference'], 'expires_at' => $locked['expires_at']]),
        ], $scope);
        $clientQuote = $record->snapshot;
        unset($clientQuote['source_rate_ten_thousandths'], $clientQuote['spread_basis_points'], $clientQuote['provider_lock_reference']);

        return Result::success([
            ...$clientQuote,
            ...($scope === RemittanceChannels::LOCAL ? ['simulated' => true] : []),
            'record_no' => $record->reference,
        ]);
    }

    /** 后台可按记录编号或会员编号查询已保存的试算快照。 */
    public function records(int $page, ?string $reference, ?int $member): Result
    {
        $rows = $this->records->page($page, $reference, $member);
        $items = array_map(static fn (RemittanceQuoteRecord $row): array => [
            ...$row->snapshot,
            'record_no' => $row->reference,
            'member_id' => (string) $row->member_id,
            'created_at' => $row->created_at->toISOString(),
        ], $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    /** 使用试算时的客户费用与运营提供的点差收入和结算成本。 */
    public function margin(string $reference, int $fxIncomeMinor, int $costMinor): Result
    {
        $record = $this->records->findByReference($reference);
        if ($record === null) {
            return Result::fail('试算记录不存在', 404);
        }

        $quote = $record->snapshot;
        $feeIncome = $quote['base_fee_minor'] - $quote['discount_minor'] + $quote['channel_fee_minor'];
        $gross = $feeIncome + $fxIncomeMinor - $costMinor;

        return Result::success([
            'funding_currency' => $quote['funding_currency'],
            'fee_income_minor' => $feeIncome,
            'fx_income_minor' => $fxIncomeMinor,
            'cost_minor' => $costMinor,
            'gross_margin_minor' => $gross,
            'gross_margin_percent_of_principal' => round($gross * 100 / $quote['principal_minor'], 2),
        ]);
    }
}
