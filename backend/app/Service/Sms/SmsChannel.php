<?php

namespace App\Service\Sms;

/** 短信发送渠道，生产环境按供应商实现并在容器中绑定。 */
interface SmsChannel
{
    /**
     * 发送验证码；发送失败时抛出异常，由调用方统一转成业务错误。
     */
    public function send(string $mobileE164, string $code, string $scene): void;
}
