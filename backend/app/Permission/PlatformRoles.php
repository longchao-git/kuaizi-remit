<?php

namespace App\Permission;

use App\Model\System\User;
use Laravel\Sanctum\PersonalAccessToken;

/** 员工角色与当前已实现的权限对应。 */
final class PlatformRoles
{
    public const ASSIGNABLE = ['operations', 'support', 'read_only', 'finance'];

    public const MANAGE_ACCESS = 'remit:staff:manage';

    private const OPERATIONS = [
        RemittancePermission::MANAGE_CLOSURES,
        RemittancePermission::MANAGE_PUSH_PLANS,
        RemittancePermission::MANAGE_RULES,
        RemittancePermission::VIEW_MEMBERS,
        RemittancePermission::VIEW_ORDERS,
        RemittancePermission::SYNC_ORDERS,
        RemittancePermission::MANAGE_MATERIALS,
        RemittancePermission::SEND_NOTIFICATION,
        RemittancePermission::MANAGE_COUPONS,
        RemittancePermission::VIEW_STATUS_REASONS,
    ];

    public static function administrator(User $user): bool
    {
        return $user->is_platform_staff && in_array(strtolower($user->email), config('remit.platform_admin_emails', []), true);
    }

    public static function abilities(User $user): array
    {
        if (! $user->is_platform_staff) {
            return [];
        }

        if (self::administrator($user)) {
            return [...self::OPERATIONS, RemittancePermission::SUPPORT, RemittancePermission::MANAGE_RECONCILIATION, self::MANAGE_ACCESS];
        }

        return match ($user->platform_role) {
            'operations' => self::OPERATIONS,
            'finance' => [RemittancePermission::VIEW_ORDERS, RemittancePermission::MANAGE_RECONCILIATION],
            'support' => [RemittancePermission::VIEW_MEMBERS, RemittancePermission::VIEW_ORDERS,
                RemittancePermission::SUPPORT, RemittancePermission::VIEW_STATUS_REASONS],
            'read_only' => [RemittancePermission::VIEW_MEMBERS, RemittancePermission::VIEW_ORDERS, RemittancePermission::VIEW_STATUS_REASONS],
            // 保留已有员工权限，管理员可逐个分配角色。
            null => [...self::OPERATIONS, RemittancePermission::SUPPORT],
            default => [],
        };
    }

    public static function allows(User $user, mixed $token, string $permission): bool
    {
        return $token instanceof PersonalAccessToken
            && $token->name === 'platform'
            && $token->can($permission)
            && in_array($permission, self::abilities($user), true);
    }
}
