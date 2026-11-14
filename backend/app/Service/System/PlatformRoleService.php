<?php

namespace App\Service\System;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\System\User;
use App\Permission\PlatformRoles;
use App\Repository\System\UserRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

/** 平台员工分工和角色变更审计。 */
#[Singleton]
final class PlatformRoleService
{
    /** 角色修改、令牌撤销及审计记录使用同一事务。 */
    public function __construct(private UserRepository $users, private DatabaseManager $database) {}

    /** 只列出后台员工，角色管理员由部署配置授予。 */
    public function staff(int $page): Result
    {
        $rows = $this->users->staffPage($page);
        $items = array_map(static fn (User $staff) => [
            'id' => (string) $staff->id,
            'name' => $staff->name,
            'email' => $staff->email,
            'role' => PlatformRoles::administrator($staff) ? 'administrator' : ($staff->platform_role ?? 'legacy'),
        ], $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    /** 更新可分配角色并撤销旧会话，防止员工继续使用原权限。 */
    public function assign(User $operator, int $id, string $role): Result
    {
        return $this->database->transaction(function () use ($operator, $id, $role): Result {
            $target = $this->users->lockStaff($id);
            if ($target === null) {
                return Result::fail('员工不存在', 404);
            }
            if (PlatformRoles::administrator($target)) {
                return Result::fail('管理员角色由部署配置管理', 409);
            }

            $before = $target->platform_role;
            if ($before !== $role) {
                $this->users->saveStaffRole($target, $role);
                $this->users->recordRoleChange($operator, $target, $before, $role);
            }

            return Result::success(['id' => (string) $target->id, 'role' => $role]);
        }, attempts: 3);
    }

    /** 返回操作人、目标员工和修改前后角色。 */
    public function audits(int $page): Result
    {
        $rows = $this->users->staffAuditPage($page);

        return Result::success(PaginationPayload::from($rows->items(), $rows->total(), $page, $rows->perPage())->toArray());
    }
}
