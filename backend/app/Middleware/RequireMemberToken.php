<?php

namespace App\Middleware;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Model\System\User;
use App\Service\System\MemberSessionService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** App 会话和报价只接受非员工会员的 Bearer 令牌。 */
final class RequireMemberToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && ! $user->is_platform_staff, 403);

        $token = $user->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken && $token->name !== 'platform', 403);

        if ($token->name === MemberSessionService::TEST_SMS_TOKEN_NAME) {
            $path = $request->path();
            $path = str_starts_with($path, 'api/') ? substr($path, 4) : $path;
            $externalWrite = ! $request->isMethodSafe() && (
                in_array($path, ['app/kyc/idv', 'app/kyc/profile', 'app/remittance/quotes', 'app/remittance/orders'], true)
                || str_starts_with($path, 'app/remittance/materials/')
            );
            if ($externalWrite || $path === 'app/remittance/payout-accounts') {
                return ResponseResult::json(Result::fail('测试账号暂不支持实名认证或真实资金操作', 403), 403);
            }
        }

        return $next($request);
    }
}
