<?php

namespace App\Service\Remittance\Yaband;

/** 校验白标字段表，不采纳与字段表冲突的旧示例格式，也不开放正式付款。 */
final class YabandWhiteLabelPaymentRequest extends YabandPaymentRequest
{
    /** 固定模式名称不来自请求参数。 */
    protected function modeLabel(): string
    {
        return '白标';
    }
}
