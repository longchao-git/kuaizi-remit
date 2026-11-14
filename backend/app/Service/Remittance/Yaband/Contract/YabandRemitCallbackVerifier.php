<?php

namespace App\Service\Remittance\Yaband\Contract;

interface YabandRemitCallbackVerifier
{
    /** 按汇款项目的回调规则验签。原始请求字节与解析后的数据均可用。 */
    public function verifies(string $event, string $body, array $payload): bool;
}
