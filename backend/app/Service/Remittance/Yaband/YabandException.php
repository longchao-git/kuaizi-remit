<?php

namespace App\Service\Remittance\Yaband;

use RuntimeException;

/** 固定错误信息可进入日志。上游响应可能包含身份或支付信息。 */
final class YabandException extends RuntimeException
{
    /** 只保留已知接口路径和数值业务码，消息及路径均不保存上游原文或凭据。 */
    public function __construct(string $message, public readonly ?int $upstreamCode = null, public readonly ?string $upstreamPath = null)
    {
        parent::__construct($message);
    }
}
