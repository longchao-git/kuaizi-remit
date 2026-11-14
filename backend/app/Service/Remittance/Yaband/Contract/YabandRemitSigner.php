<?php

namespace App\Service\Remittance\Yaband\Contract;

interface YabandRemitSigner
{
    /** 以雅本汇款项目确认的签名规则对请求参数签名。 */
    public function sign(string $path, array $payload): string;
}
