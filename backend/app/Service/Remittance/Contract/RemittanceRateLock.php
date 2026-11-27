<?php

namespace App\Service\Remittance\Contract;

interface RemittanceRateLock
{
    /** @return array{reference: string, expires_at: int, rate_ten_thousandths: int} */
    public function lock(int $member, array $request): array;
}
