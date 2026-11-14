<?php

namespace App\Providers;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Service\System\MemberSessionService;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $token, bool $valid): bool {
            if (! $valid) {
                return false;
            }
            if ($token->name === 'platform') {
                $staff = $token->tokenable;

                return $staff instanceof User && $staff->is_platform_staff
                    && ($token->abilities === ['*']
                        ? $staff->platform_role === null
                        : array_diff($token->abilities ?? [], PlatformRoles::abilities($staff)) === []);
            }
            if ($token->name === MemberSessionService::LOCAL_SMS_TOKEN_NAME) {
                $member = $token->tokenable;

                return app(MemberSessionService::class)->localSmsEnabled()
                    && $member instanceof User && ! $member->is_platform_staff && $member->phone_e164 !== null;
            }
            if ($token->name !== MemberSessionService::LOCAL_TOKEN_NAME) {
                return $valid;
            }

            // 已发出的测试令牌也随本地配置关闭而立即失效。
            $email = app(MemberSessionService::class)->localLoginEmail();

            return $email !== null && $token->tokenable?->email === $email && ! $token->tokenable->is_platform_staff;
        });
    }
}
