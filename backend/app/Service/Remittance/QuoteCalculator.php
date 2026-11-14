<?php

namespace App\Service\Remittance;

use InvalidArgumentException;

final class QuoteCalculator
{
    /** 根据已选规则计算欧元付款费用和人民币预计到账金额。 */
    public function calculate(
        int $principalMinor,
        int $rateTenThousandths,
        int $baseFeeMinor,
        int $discountMinor,
        int $channelFeeBasisPoints,
        int $etaThresholdMinor,
        string $etaBelow,
        string $etaAtOrAbove,
        string $paymentNotice,
    ): array {
        if ($principalMinor < 1 || $principalMinor > 99_999_999_900
            || $rateTenThousandths < 1 || $rateTenThousandths > 1_000_000
            || $baseFeeMinor < 0 || $baseFeeMinor > 1_000_000
            || $discountMinor < 0 || $discountMinor > $baseFeeMinor
            || $channelFeeBasisPoints < 0 || $channelFeeBasisPoints > 10_000
            || $etaThresholdMinor < 1 || trim($etaBelow) === '' || trim($etaAtOrAbove) === '') {
            throw new InvalidArgumentException('报价金额、汇率或规则无效。');
        }

        $channelFee = intdiv($principalMinor * $channelFeeBasisPoints + 5_000, 10_000);
        $payout = intdiv($principalMinor * $rateTenThousandths + 5_000, 10_000);
        $feePercent = rtrim(rtrim(number_format($channelFeeBasisPoints / 100, 2, '.', ''), '0'), '.');

        return [
            'principal_minor' => $principalMinor,
            'funding_currency' => 'EUR',
            'payout_minor' => $payout,
            'payout_currency' => 'CNY',
            'rate_ten_thousandths' => $rateTenThousandths,
            'base_fee_minor' => $baseFeeMinor,
            'discount_minor' => $discountMinor,
            'channel_fee_basis_points' => $channelFeeBasisPoints,
            'channel_fee_minor' => $channelFee,
            'total_payable_minor' => $principalMinor + $baseFeeMinor - $discountMinor + $channelFee,
            'eta' => $principalMinor < $etaThresholdMinor ? $etaBelow : $etaAtOrAbove,
            'payment_notice' => str_replace('{费率}', $feePercent.'%', $paymentNotice),
        ];
    }
}
