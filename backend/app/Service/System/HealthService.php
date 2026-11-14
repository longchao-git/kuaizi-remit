<?php

namespace App\Service\System;

use App\Common\Result;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final class HealthService
{
    /** @return Result<array{status: string}> */
    public function check(): Result
    {
        return Result::success(['status' => 'ok']);
    }
}
