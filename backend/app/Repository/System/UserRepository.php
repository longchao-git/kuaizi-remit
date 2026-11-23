<?php

namespace App\Repository\System;

use App\Model\System\User;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/** @extends AbstractRepository<User> */
final class UserRepository extends AbstractRepository
{
    protected function model(): User
    {
        return new User;
    }

    /** 后台允许专用用户名或原邮箱登录，两个条件均必须属于员工。 */
    public function findPlatformStaff(string $login): ?User
    {
        return $this->query()->where('is_platform_staff', true)
            ->where(fn (Builder $query) => $query->where('username', $login)->orWhere('email', $login))
            ->first();
    }

    public function startStaffSession(User $user, array $abilities): NewAccessToken
    {
        return $user->createToken('platform', $abilities, now()->addHours(8));
    }

    public function findByEmail(string $email): ?User
    {
        return $this->query()->where('email', $email)->first();
    }

    /** 员工账号不能作为会员业务的归属对象。 */
    public function memberExists(int $id): bool
    {
        return $this->query()->where('is_platform_staff', false)->whereKey($id)->exists();
    }

    /** 平台员工及已授予的角色。 */
    public function staffPage(int $page): LengthAwarePaginator
    {
        return $this->query()->where('is_platform_staff', true)->orderBy('id')->paginate(20, page: $page);
    }

    public function lockStaff(int $id): ?User
    {
        return $this->query()->where('is_platform_staff', true)->lockForUpdate()->find($id);
    }

    public function saveStaffRole(User $staff, string $role): void
    {
        $staff->forceFill(['platform_role' => $role])->saveOrFail();
        $staff->tokens()->where('name', 'platform')->delete();
    }

    public function recordRoleChange(User $operator, User $target, ?string $before, string $after): void
    {
        DB::table('platform_role_audits')->insert([
            'operator_id' => $operator->id, 'target_id' => $target->id,
            'operator_name' => $operator->name, 'target_name' => $target->name,
            'before_role' => $before, 'after_role' => $after, 'created_at' => now(),
        ]);
    }

    public function staffAuditPage(int $page): LengthAwarePaginator
    {
        return DB::table('platform_role_audits')->select('id', 'operator_name', 'target_name', 'before_role', 'after_role', 'created_at')
            ->orderByDesc('id')->paginate(20, page: $page);
    }

    /** 查真实会员，不把后台员工混入结果。 */
    public function memberPage(string $keyword, int $page): LengthAwarePaginator
    {
        $query = $this->query()->where('is_platform_staff', false);
        if (ctype_digit($keyword)) {
            $id = filter_var($keyword, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $query->whereKey($id === false ? 0 : $id);
        } elseif ($keyword !== '') {
            $pattern = '%'.strtr($keyword, ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
            $query->where(function (Builder $search) use ($pattern): void {
                $search->whereRaw("LOWER(name) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(email) LIKE LOWER(?) ESCAPE '!'", [$pattern])
                    ->orWhereRaw("phone_e164 LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        return $query->select(['id', 'name', 'email', 'phone_e164', 'created_at'])->orderByDesc('id')->paginate(20, page: $page);
    }

    public function createLocalMember(string $email, string $password): void
    {
        $this->query()->create(['name' => '汇款联调账号', 'email' => $email, 'password' => $password]);
    }

    /** 初始化管理端员工账号。 */
    public function createPlatformStaff(string $email, string $name, string $password): User
    {
        $staff = new User;
        $staff->forceFill([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_platform_staff' => true,
        ])->saveOrFail();

        return $staff;
    }

    /** 重置已有账号为可登录的员工账号，并作废旧的后台令牌。 */
    public function resetPlatformStaff(User $staff, string $name, string $password): void
    {
        $staff->forceFill([
            'name' => $name,
            'password' => $password,
            'is_platform_staff' => true,
        ])->saveOrFail();
        $staff->tokens()->where('name', 'platform')->delete();
    }

    /** 手机号为独立唯一标识，旧表必填邮箱保留为内部值。 */
    public function findOrRegisterMobile(string $mobile): User
    {
        return $this->query()->firstOrCreate(['phone_e164' => $mobile], [
            'name' => '会员 '.substr($mobile, -4),
            'email' => Str::uuid().'@mobile.invalid',
            'password' => Str::random(64),
        ]);
    }

    public function startMemberSession(User $user, string $name): NewAccessToken
    {
        return $user->createToken($name, [], now()->addHours(2));
    }

    public function revokeSession(PersonalAccessToken $token): void
    {
        $token->delete();
    }
}
