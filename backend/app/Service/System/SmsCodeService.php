<?php

namespace App\Service\System;

use App\Repository\System\SmsCodeRepository;
use App\Service\Sms\SmsChannel;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Throwable;

/** 生成、校验短信验证码；验证码只存哈希，单次消费。 */
#[Singleton]
final class SmsCodeService
{
    public const SCENE_LOGIN = 'login';

    /** 测试码和普通短信共用哈希存储及单次消费规则。 */
    public function __construct(
        private SmsCodeRepository $codes,
        private SmsChannel $channel,
        private DatabaseManager $database,
        private TestSmsLogin $testLogin,
    ) {}

    /**
     * 发送验证码。仅本地且开启 auto-fill 时返回 test_code。
     *
     * @return array{sent: true, test_code?: string}
     */
    public function send(string $mobile, ?string $ip = null): array
    {
        $test = $this->testLogin->allows($mobile);
        $code = $test ? $this->testLogin->code() : (string) random_int(100000, 999999);
        $ttl = (int) config('remit.sms.code_ttl', 300);

        $this->codes->purgeExpired();
        $this->codes->replace($mobile, self::SCENE_LOGIN, $this->hash($code), now()->addSeconds($ttl), $ip);

        try {
            if (! $test) {
                $this->channel->send($mobile, $code, self::SCENE_LOGIN);
            }
        } catch (Throwable $error) {
            $this->codes->forget($mobile, self::SCENE_LOGIN);
            report($error);

            throw new RuntimeException('短信发送失败，请稍后重试', 0, $error);
        }

        return ! $test && $this->testCodeEnabled()
            ? ['sent' => true, 'test_code' => $code]
            : ['sent' => true];
    }

    /** 校验并消费验证码；错误次数、过期与单次使用都在事务内处理。 */
    public function verify(string $mobile, string $code): bool
    {
        return $this->database->transaction(function () use ($mobile, $code): bool {
            $record = $this->codes->lockActive($mobile, self::SCENE_LOGIN);

            if ($record === null || $record->expires_at->isPast()) {
                if ($record !== null) {
                    $this->codes->forget($mobile, self::SCENE_LOGIN);
                }

                return false;
            }

            $maxAttempts = (int) config('remit.sms.max_attempts', 5);
            if ($record->attempts >= $maxAttempts) {
                $this->codes->forget($mobile, self::SCENE_LOGIN);

                return false;
            }

            $record->forceFill(['attempts' => $record->attempts + 1])->save();

            if (! hash_equals($record->code_hash, $this->hash($code))) {
                return false;
            }

            $this->codes->forget($mobile, self::SCENE_LOGIN);

            return true;
        }, attempts: 3);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    private function testCodeEnabled(): bool
    {
        return app()->isLocal() && config('remit.sms.test_autofill') === true;
    }
}
