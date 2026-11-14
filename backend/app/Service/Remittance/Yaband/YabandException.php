<?php

namespace App\Service\Remittance\Yaband;

use RuntimeException;

/** 固定错误信息可进入日志。上游响应可能包含身份或支付信息。 */
final class YabandException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $upstreamCode = null)
    {
        parent::__construct($message);
    }
}
