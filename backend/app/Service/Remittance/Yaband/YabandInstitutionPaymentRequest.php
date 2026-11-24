<?php

namespace App\Service\Remittance\Yaband;

/** 校验机构字段表，不推断本人核验结果或正式成交条件。 */
final class YabandInstitutionPaymentRequest extends YabandPaymentRequest
{
    /** 固定模式名称不来自请求参数。 */
    protected function modeLabel(): string
    {
        return '机构';
    }
}
