<?php

namespace App\Providers;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use App\Service\Remittance\Yaband\YabandRemitHmacCallbackVerifier;
use App\Service\Remittance\Yaband\YabandRemitHmacSigner;
use App\Service\Sms\LogSmsChannel;
use App\Service\Sms\NullSmsChannel;
use App\Service\Sms\SmsChannel;
use App\Service\System\MemberSessionService;
use App\Service\System\TestSmsLogin;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $secretKey = config('yaband_remit.secret_key');
        if (is_string($secretKey) && trim($secretKey) !== '') {
            $this->app->singleton(
                YabandRemitSigner::class,
                static fn (): YabandRemitSigner => new YabandRemitHmacSigner($secretKey),
            );
            $mid = config('yaband_remit.mid');
            if (config('yaband_remit.callback_verification_enabled') === true && is_string($mid) && trim($mid) !== '') {
                $this->app->singleton(
                    YabandRemitCallbackVerifier::class,
                    static fn (): YabandRemitCallbackVerifier => new YabandRemitHmacCallbackVerifier($secretKey, $mid),
                );
            }
        }
        $this->app->singleton(SmsChannel::class, static function (): SmsChannel {
            return match ((string) config('remit.sms.channel')) {
                'log' => new LogSmsChannel,
                default => new NullSmsChannel,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

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
            if ($token->name === MemberSessionService::TEST_SMS_TOKEN_NAME) {
                $member = $token->tokenable;

                return $member instanceof User && app(TestSmsLogin::class)->allowsMember($member);
            }
            if ($token->name !== MemberSessionService::LOCAL_TOKEN_NAME) {
                return $valid;
            }

            // 已发出的测试令牌也随本地配置关闭而立即失效。
            $email = app(MemberSessionService::class)->localLoginEmail();

            return $email !== null && $token->tokenable?->email === $email && ! $token->tokenable->is_platform_staff;
        });
    }

    /** 短信验证码按手机号与 IP 双维度限流，避免只换 IP 或只换号码绕过。 */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('sms-send', static fn (Request $request): array => [
            Limit::perMinute(1)->by('sms-send:phone:'.(string) $request->input('mobile')),
            Limit::perHour(5)->by('sms-send:phone:'.(string) $request->input('mobile')),
            Limit::perDay(10)->by('sms-send:phone:'.(string) $request->input('mobile')),
            Limit::perMinute(5)->by('sms-send:ip:'.$request->ip()),
        ]);

        RateLimiter::for('sms-login', static fn (Request $request): array => [
            Limit::perMinute(5)->by('sms-login:phone:'.(string) $request->input('mobile')),
            Limit::perMinute(10)->by('sms-login:ip:'.$request->ip()),
        ]);
    }
}
