<?php

namespace App\Repository\System;

use App\Model\System\SmsCode;
use App\Repository\AbstractRepository;
use DateTimeInterface;

/** @extends AbstractRepository<SmsCode> */
final class SmsCodeRepository extends AbstractRepository
{
    protected function model(): SmsCode
    {
        return new SmsCode;
    }

    /** 同一手机号与场景只保留最新验证码，旧码直接覆盖。 */
    public function replace(string $mobile, string $scene, string $hash, DateTimeInterface $expiresAt, ?string $ip): SmsCode
    {
        return $this->query()->updateOrCreate(
            ['mobile_e164' => $mobile, 'scene' => $scene],
            [
                'code_hash' => $hash,
                'attempts' => 0,
                'expires_at' => $expiresAt,
                'sent_at' => now(),
                'ip' => $ip,
            ],
        );
    }

    public function lockActive(string $mobile, string $scene): ?SmsCode
    {
        return $this->query()->where('mobile_e164', $mobile)->where('scene', $scene)->lockForUpdate()->first();
    }

    public function forget(string $mobile, string $scene): void
    {
        $this->query()->where('mobile_e164', $mobile)->where('scene', $scene)->delete();
    }

    public function purgeExpired(): void
    {
        $this->query()->where('expires_at', '<', now())->delete();
    }
}
