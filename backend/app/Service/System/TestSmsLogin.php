<?php

namespace App\Service\System;

use App\Model\System\User;

/** 只允许显式配置的沙箱测试会员使用固定验证码。 */
final class TestSmsLogin
{
    /** 测试模式默认关闭，正式第三方地址、空手机号或无效验证码均不开放。 */
    public function enabled(): bool
    {
        return config('remit.test_sms_login.enabled') === true
            && rtrim((string) config('yaband_remit.base_url'), '/') === 'https://remittestv2.yabandpay.com'
            && preg_match('/^\+34[67][0-9]{8}$/D', (string) config('remit.test_sms_login.mobile')) === 1
            && preg_match('/^[0-9]{6}$/D', $this->code()) === 1;
    }

    /** 固定验证码只适用于唯一配置的测试号码。 */
    public function allows(string $mobile): bool
    {
        return $this->enabled() && hash_equals((string) config('remit.test_sms_login.mobile'), $mobile);
    }

    /** 测试码由私有运行配置注入，不把真实测试凭据写入仓库。 */
    public function code(): string
    {
        return (string) config('remit.test_sms_login.code');
    }

    /** 确定内部邮箱用于区分专用测试账号与同手机号的普通账号。 */
    public function memberEmail(string $mobile): string
    {
        return 'test-sms-'.hash('sha256', $mobile).'@members.invalid';
    }

    /** 已有测试会话随配置关闭而失效，也不能接管普通会员或员工。 */
    public function allowsMember(User $member): bool
    {
        $mobile = $member->phone_e164;

        return ! $member->is_platform_staff && is_string($mobile) && $this->allows($mobile)
            && hash_equals($this->memberEmail($mobile), $member->email);
    }
}
