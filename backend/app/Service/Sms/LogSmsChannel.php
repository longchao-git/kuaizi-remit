<?php

namespace App\Service\Sms;

use Illuminate\Support\Facades\Log;

/** 本地开发渠道：只写日志，不真正发送短信。 */
final class LogSmsChannel implements SmsChannel
{
    public function send(string $mobileE164, string $code, string $scene): void
    {
        Log::info('sms.code', [
            'mobile' => $this->mask($mobileE164),
            'scene' => $scene,
            'code' => $code,
        ]);
    }

    private function mask(string $mobile): string
    {
        return strlen($mobile) > 5
            ? substr($mobile, 0, 3).'****'.substr($mobile, -2)
            : '****';
    }
}
