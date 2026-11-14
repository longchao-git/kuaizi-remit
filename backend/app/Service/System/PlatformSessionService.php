<?php

namespace App\Service\System;

use App\Common\Result;
use App\Constants\Common\ErrorCode;
use App\Permission\PlatformRoles;
use App\Repository\System\UserRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

#[Singleton]
final class PlatformSessionService
{
    public function __construct(private UserRepository $users) {}

    public function login(string $username, string $password): Result
    {
        $user = $this->users->findPlatformStaff($username);
        if ($user === null || ! Hash::check($password, $user->password)) {
            return Result::fail('账号或密码错误', ErrorCode::INVALID_CREDENTIALS);
        }

        $token = $this->users->startStaffSession($user, PlatformRoles::abilities($user));

        return Result::success(['token' => $token->plainTextToken]);
    }

    public function logout(PersonalAccessToken $token): void
    {
        $this->users->revokeSession($token);
    }
}
