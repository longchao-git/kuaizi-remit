<?php

namespace App\Middleware;

use App\Model\System\User;
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

        return $next($request);
    }
}
