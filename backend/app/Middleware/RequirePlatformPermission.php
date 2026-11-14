<?php

namespace App\Middleware;

use App\Model\System\User;
use App\Permission\PlatformRoles;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 仅接受带指定权限的平台员工令牌。 */
final class RequirePlatformPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User && PlatformRoles::allows($user, $user->currentAccessToken(), $permission), 403);

        return $next($request);
    }
}
