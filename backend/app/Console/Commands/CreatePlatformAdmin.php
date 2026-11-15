<?php

namespace App\Console\Commands;

use App\Repository\System\UserRepository;
use Illuminate\Console\Command;

/** 初始化可登录管理端的平台员工账号。 */
final class CreatePlatformAdmin extends Command
{
    protected $signature = 'remit:create-admin
        {email? : 管理员邮箱，默认取 REMIT_PLATFORM_ADMIN_EMAILS 的第一个}
        {--name=平台管理员 : 后台显示名称}
        {--password= : 登录密码（8–72 字节），省略时交互输入}
        {--force : 邮箱已存在时重置密码并恢复员工标记}';

    protected $description = '创建或重置平台管理员账号';

    public function handle(UserRepository $users): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: (config('remit.platform_admin_emails')[0] ?? ''))));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('邮箱无效：请传入邮箱，或先在 .env 设置 REMIT_PLATFORM_ADMIN_EMAILS。');

            return self::FAILURE;
        }

        $password = $this->option('password');
        if (! is_string($password) || $password === '') {
            $password = $this->secret('管理员密码（8–72 字节）');
        }

        if (! is_string($password) || strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            $this->error('密码长度须为 8–72 字节。');

            return self::FAILURE;
        }

        $name = (string) $this->option('name');
        $staff = $users->findByEmail($email);

        if ($staff === null) {
            $staff = $users->createPlatformStaff($email, $name, $password);
            $this->info("已创建管理员账号：{$email}（ID {$staff->id}）");
        } elseif ($this->option('force')) {
            $users->resetPlatformStaff($staff, $name, $password);
            $this->info("已重置管理员账号：{$email}（ID {$staff->id}）");
        } else {
            $this->error('该邮箱已有账号；如需重置密码和员工标记请加 --force。');

            return self::FAILURE;
        }

        if (! in_array($email, config('remit.platform_admin_emails', []), true)) {
            $this->warn('该邮箱不在 REMIT_PLATFORM_ADMIN_EMAILS 中，当前只有默认员工权限；加入 backend/.env 后重新登录即可获得管理员权限。');
        }

        return self::SUCCESS;
    }
}
