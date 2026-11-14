<?php

namespace App\Console\Commands;

use App\Repository\System\UserRepository;
use App\Service\System\MemberSessionService;
use Illuminate\Console\Command;

/** 在本地库创建一个联调用的账号，禁止覆盖已有账号。 */
final class CreateLocalRemitAccount extends Command
{
    protected $signature = 'remit:create-local-account';

    protected $description = '创建本地汇款联调账号（仅 local 环境）';

    public function handle(MemberSessionService $sessions, UserRepository $users): int
    {
        $email = $sessions->localLoginEmail();
        if ($email === null) {
            $this->error('请使用本地环境并设置 REMIT_LOCAL_LOGIN_EMAIL。');

            return self::FAILURE;
        }

        if ($users->findByEmail($email) !== null) {
            $this->error('该邮箱已有账号，请沿用现有联调账号或换一个专用邮箱。');

            return self::FAILURE;
        }

        $password = $this->secret('联调密码（至少 6 位，仅限本地）');
        if (! is_string($password) || strlen($password) < 6 || strlen($password) > 72 || str_contains($password, "\0")) {
            $this->error('密码长度须为 6–72 字节。');

            return self::FAILURE;
        }

        $users->createLocalMember($email, $password);
        $this->info('已创建联调账号。');

        return self::SUCCESS;
    }
}
