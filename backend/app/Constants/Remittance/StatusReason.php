<?php

namespace App\Constants\Remittance;

/** 项目内置状态原因，用于向客服和用户解释处理进度。 */
final class StatusReason
{
    public const DEFINITIONS = [
        'PAYMENT_CHANNEL_PROCESSING' => [
            'title' => '正在确认付款',
            'guidance' => '核对付款通道进度，提示客户勿重复付款。',
        ],
        'KYC_PENDING_REVIEW' => [
            'title' => '身份资料审核中',
            'guidance' => '查看上游认证状态，等待审核结果。',
        ],
        'UPSTREAM_TIMEOUT' => [
            'title' => '上游确认超时',
            'guidance' => '核查上游状态，再向客户反馈。',
        ],
    ];
}
