<?php

namespace App\Service\Remittance\Yaband\Contract;

interface YabandRateLock
{
    /**
     * 返回真实锁汇编号、过期时间以及按万分位表示的参考汇率。
     *
     * @return array{reference: string, expires_at: int, rate_ten_thousandths: int}
     */
    public function lock(int $member, array $request): array;
}
