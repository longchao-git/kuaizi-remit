<?php

namespace App\Service\System;

use App\Common\Result;
use App\Constants\Common\ErrorCode;
use App\Model\System\User;
use App\Repository\System\UserRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;

/** 创建和读取 App 会员会话。 */
#[Singleton]
final class MemberSessionService
{
    public const LOCAL_TOKEN_NAME = 'remit-local';

    public const LOCAL_SMS_TOKEN_NAME = 'remit-local-sms';

    public const TEST_SMS_TOKEN_NAME = 'remit-test-sms';

    /** 复用会员仓储、验证码与事务，并单独注入沙箱测试登录策略。 */
    public function __construct(
        private UserRepository $users,
        private DatabaseManager $database,
        private SmsCodeService $codes,
        private TestSmsLogin $testLogin,
    ) {}

    /** 只有本地环境且明确设置账号邮箱，才允许使用联调登录。 */
    public function localLoginEmail(): ?string
    {
        $email = config('remit.local_login_email');

        return app()->isLocal() && is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL)
            ? strtolower($email)
            : null;
    }

    /** 验证唯一指定的联调账号，返回短期 App 令牌和资料。 */
    public function loginLocal(string $email, string $password): Result
    {
        $configuredEmail = $this->localLoginEmail();
        $member = $configuredEmail !== null && strtolower($email) === $configuredEmail
            ? $this->users->findByEmail($configuredEmail)
            : null;

        if ($member === null || $member->is_platform_staff
            || strlen($password) > 72 || str_contains($password, "\0")
            || ! Hash::check($password, $member->password)) {
            return Result::fail('账号或密码错误', ErrorCode::INVALID_CREDENTIALS);
        }

        return $this->start($member, self::LOCAL_TOKEN_NAME);
    }

    public function localSmsEnabled(): bool
    {
        return app()->isLocal() && config('remit.local_sms_login') === true;
    }

    /** 本地短信与显式开启的专用沙箱测试登录分别判定。 */
    public function smsEnabled(): bool
    {
        return $this->localSmsEnabled() || $this->testLogin->enabled();
    }

    /** 非本地环境只接受获准的唯一测试号码。 */
    public function allowsSmsMobile(string $mobile): bool
    {
        return $this->localSmsEnabled() || $this->testLogin->allows($mobile);
    }

    /** 让发送响应明确区分测试码与真实短信。 */
    public function isTestSmsMobile(string $mobile): bool
    {
        return $this->testLogin->allows($mobile);
    }

    /** 校验短信验证码后登录，首次登录自动创建会员。 */
    public function loginSms(string $mobile, string $code): Result
    {
        if (! $this->allowsSmsMobile($mobile) || ! $this->codes->verify($mobile, $code)) {
            return Result::fail('验证码错误', ErrorCode::INVALID_CREDENTIALS);
        }

        return $this->database->transaction(function () use ($mobile): Result {
            $test = $this->testLogin->allows($mobile);
            $member = $test
                ? $this->users->findOrRegisterTestMobile($mobile, $this->testLogin->memberEmail($mobile))
                : $this->users->findOrRegisterMobile($mobile);
            if ($member->is_platform_staff || ($test && ! $this->testLogin->allowsMember($member))) {
                return Result::fail('无法登录此账号', ErrorCode::INVALID_CREDENTIALS);
            }

            return $this->start($member, $test ? self::TEST_SMS_TOKEN_NAME : self::LOCAL_SMS_TOKEN_NAME);
        }, attempts: 3);
    }

    private function start(User $member, string $name): Result
    {
        $token = $this->users->startMemberSession($member, $name);

        return Result::success(['token' => $token->plainTextToken, 'member' => $this->profile($member)]);
    }

    /** 返回 App 需要的会员标识和显示名，不暴露账号敏感字段。 */
    public function profile(User $member): array
    {
        return ['memberId' => (string) $member->getKey(), 'userName' => $member->name];
    }
}
