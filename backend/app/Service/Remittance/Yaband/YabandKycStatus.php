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
            'can_submit_profile' => in_array($statuses['idv_status_code'], ['2006', '2010'], true),
            'kyc_passed' => $statuses['kyc_status_code'] === '5004',
            'remittance_enabled' => $statuses['user_status_code'] === '1001' && $statuses['remit_status_code'] === '4001',
        ];
    }
}
