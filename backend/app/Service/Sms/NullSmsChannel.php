<?php

namespace App\Service\Sms;

use RuntimeException;

/** 未配置真实供应商时的占位渠道，避免静默丢弃验证码。 */
final class NullSmsChannel implements SmsChannel
{
    public function send(string $mobileE164, string $code, string $scene): void
    {
        throw new RuntimeException('短信渠道未配置');
    }
}
