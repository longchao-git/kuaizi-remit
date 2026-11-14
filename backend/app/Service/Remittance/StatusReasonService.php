<?php

namespace App\Service\Remittance;

use App\Common\Result;
use App\Constants\Remittance\StatusReason;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final class StatusReasonService
{
    public function list(string $query): Result
    {
        $rows = [];
        foreach (StatusReason::DEFINITIONS as $code => $definition) {
            if ($query === '' || mb_stripos(implode(' ', [$code, ...$definition]), $query) !== false) {
                $rows[] = ['code' => $code, ...$definition];
            }
        }

        return Result::success($rows);
    }
}
