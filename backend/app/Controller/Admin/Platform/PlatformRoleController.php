<?php

namespace App\Controller\Admin\Platform;

use App\Common\ResponseResult;
use App\Controller\AbstractController;
use App\Permission\PlatformRoles;
use App\Service\System\PlatformRoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** 角色管理员的员工列表和角色分配。 */
final class PlatformRoleController extends AbstractController
{
    public function __construct(private PlatformRoleService $roles) {}

    public function staff(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return $this->success($this->roles->staff((int) ($input['page'] ?? 1))->getData());
    }

    public function assign(Request $request, int $staff): JsonResponse
    {
        $input = $request->validate(['role' => ['required', Rule::in(PlatformRoles::ASSIGNABLE)]]);
        $result = $this->roles->assign($request->user(), $staff, $input['role']);

        return $result->isSuccess() ? $this->success($result->getData()) : ResponseResult::json($result, $result->getCode());
    }

    public function audits(Request $request): JsonResponse
    {
        $input = $request->validate(['page' => ['sometimes', 'integer', 'between:1,1000000']]);

        return $this->success($this->roles->audits((int) ($input['page'] ?? 1))->getData());
    }
}
