<?php

namespace App\Service\Remittance\Yaband;

/** 保留独立状态，以免把活体通过当作完整 KYC 通过。 */
final class YabandKycStatus
{
    public static function fromQuery(array $data): array
    {
        $statuses = [];
        foreach (['user', 'remit', 'kyc', 'idv', 'profile'] as $name) {
            $field = $name.'_status_code';
            $value = $data[$field] ?? null;
            $statuses[$field] = is_int($value) || is_string($value) ? (string) $value : null;
        }

        return [
            ...$statuses,
            'can_start_idv' => in_array($statuses['kyc_status_code'], ['5001', '5003'], true),
            'can_submit_profile' => in_array($statuses['idv_status_code'], ['2006', '2010'], true)
                && $statuses['kyc_status_code'] !== '5004' && $statuses['profile_status_code'] !== '3002',
            'guidance' => self::guidance($statuses),
            'kyc_passed' => $statuses['kyc_status_code'] === '5004',
            'remittance_enabled' => $statuses['user_status_code'] === '1001' && $statuses['remit_status_code'] === '4001',
        ];
    }

    /** 只按公开状态给出固定建议，绝不把上游自由文本、证件或活体报告传给客户端。 */
    private static function guidance(array $statuses): ?string
    {
        if ($statuses['kyc_status_code'] === '5004') {
            return null;
        }
        if ($statuses['profile_status_code'] === '3004') {
            return '证件已过期，请更新身份资料并查询认证进度。';
        }
        if ($statuses['profile_status_code'] === '3003') {
            return '身份资料未获批准，请核对证件信息与居住地址，仍无法提交时请联系客服。';
        }

        return match ($statuses['idv_status_code']) {
            '2002', '2008' => '身份验证已失效，请重新完成身份验证。',
            '2005', '2007', '2009' => '请核对证件有效期与个人资料，重新完成身份验证；仍未通过时请联系客服。',
            default => $statuses['profile_status_code'] === '3002' ? '身份资料已批准，请查询完整认证进度。' : null,
        };
    }
}
