<?php

namespace App\Service\System;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 手机号的精确查询摘要与字段加密使用不同的密钥。 */
final class PhoneLookup
{
    public function digest(string $phone): string
    {
        $key = $this->key();
        $expected = DB::table('remit_phone_key')->where('id', 1)->value('fingerprint');
        if (! is_string($expected) || ! hash_equals($expected, $this->fingerprint($key))) {
            throw new RuntimeException('手机号查询密钥与数据库配置不一致。');
        }

        return hash_hmac('sha256', 'phone-e164:'.$phone, $key);
    }

    public function configuredFingerprint(): string
    {
        return $this->fingerprint($this->key());
    }

    private function key(): string
    {
        $configured = config('remit.phone_lookup_key');
        $key = is_string($configured) ? base64_decode($configured, true) : false;
        if (! is_string($key) || strlen($key) !== 32) {
            throw new RuntimeException('手机号查询密钥未配置或格式不正确。');
        }

        return $key;
    }

    private function fingerprint(string $key): string
    {
        return hash_hmac('sha256', 'kuaizi-remit:phone-lookup-key:v1', $key);
    }
}
