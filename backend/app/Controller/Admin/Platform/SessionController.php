<?php

namespace App\Controller\Admin\Platform;

use App\Controller\AbstractController;
use App\Permission\PlatformRoles;
use App\Permission\RemittancePermission;
use App\Service\System\PlatformSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class SessionController extends AbstractController
{
    public function __construct(private PlatformSessionService $sessions) {}

    public function login(Request $request): JsonResponse
    {
        $input = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
        ]);
        $result = $this->sessions->login($input['username'], $input['password']);

        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : $this->fail($result->getCode(), $result->getMsg());
    }

    public function current(Request $request): JsonResponse
    {
        $this->staffToken($request);
        $user = $request->user();

        return $this->success(['id' => (string) $user->getKey(), 'username' => $user->username ?? $user->email, 'nickname' => $user->name]);
    }

    public function menus(Request $request): JsonResponse
    {
        $token = $this->staffToken($request);
        $user = $request->user();

        $groups = [
            ['', '', [
                ['remittance-dashboard', '经营仪表盘', 'remittance/dashboard', RemittancePermission::VIEW_ORDERS],
            ]],
            ['transfers', '汇款管理', [
                ['remittance-orders', '汇款订单', 'remittance/orders', RemittancePermission::VIEW_ORDERS],
                ['remittance-materials', '补件管理', 'remittance/materials', RemittancePermission::MANAGE_MATERIALS],
            ]],
            ['members', '用户与认证', [
                ['remittance-members', '会员中心', 'remittance/members', RemittancePermission::VIEW_MEMBERS],
                ['remittance-closures', '注销申请', 'remittance/closures', RemittancePermission::MANAGE_CLOSURES],
            ]],
            ['pricing', '报价与规则', [
                ['remittance-rules', '付款与到账规则', 'remittance/rules', RemittancePermission::MANAGE_RULES],
                ['remittance-quotes', '报价记录', 'remittance/quotes', RemittancePermission::MANAGE_RULES],
            ]],
            ['marketing', '营销与通知', [
                ['remittance-coupons', '汇款优惠券', 'remittance/coupons', RemittancePermission::MANAGE_COUPONS],
                ['remittance-push', 'Push 提醒', 'remittance/push', RemittancePermission::MANAGE_PUSH_PLANS],
            ]],
            ['support-finance', '客服与财务', [
                ['support-messages', '客服消息', 'support/messages', RemittancePermission::SUPPORT],
                ['support-tickets', '客服工单', 'support/tickets', RemittancePermission::SUPPORT],
                ['remittance-status-reasons', '状态原因码', 'remittance/status-reasons', RemittancePermission::VIEW_STATUS_REASONS],
                ['remittance-reconciliation', '对账与结算', 'remittance/reconciliation', RemittancePermission::MANAGE_RECONCILIATION],
            ]],
            ['system', '系统管理', [
                ['remittance-operations', '运营配置', 'remittance/operations', RemittancePermission::MANAGE_RULES],
                ['remittance-agreements', '协议管理', 'remittance/agreements', RemittancePermission::MANAGE_RULES],
                ['remittance-staff', '员工角色', 'remittance/staff', PlatformRoles::MANAGE_ACCESS],
                ['remittance-audit', '操作审计', 'remittance/audit', PlatformRoles::MANAGE_ACCESS],
            ]],
        ];

        $menus = [];
        foreach ($groups as [$groupId, $groupName, $entries]) {
            $children = [];
            foreach ($entries as [$id, $name, $component, $permission]) {
                if (PlatformRoles::allows($user, $token, $permission)) {
                    $children[] = [
                        'id' => $id,
                        'name' => $name,
                        'path' => '/system/'.$component,
                        'component' => $component,
                        'permission' => $permission,
                        'menuType' => 'MENU',
                        'visible' => true,
                    ];
                }
            }

            if ($children === []) {
                continue;
            }

            if ($groupId === '') {
                $menus = [...$menus, ...$children];

                continue;
            }

            $menus[] = [
                'id' => 'remit-group-'.$groupId,
                'name' => $groupName,
                'path' => '/system/remit-menu/'.$groupId,
                'menuType' => 'MENU',
                'visible' => true,
                'children' => $children,
            ];
        }

        return $this->success($menus);
    }

    public function permissions(Request $request): JsonResponse
    {
        $token = $this->staffToken($request);
        $user = $request->user();

        return $this->success(array_values(array_filter([
            RemittancePermission::MANAGE_RULES,
            RemittancePermission::SUPPORT,
            RemittancePermission::VIEW_MEMBERS,
            RemittancePermission::SEND_NOTIFICATION,
            RemittancePermission::MANAGE_COUPONS,
            RemittancePermission::VIEW_STATUS_REASONS,
            PlatformRoles::MANAGE_ACCESS,
            RemittancePermission::VIEW_ORDERS,
            RemittancePermission::SYNC_ORDERS,
            RemittancePermission::MANAGE_MATERIALS,
            RemittancePermission::MANAGE_CLOSURES,
            RemittancePermission::MANAGE_PUSH_PLANS,
            RemittancePermission::MANAGE_RECONCILIATION,
        ], static fn (string $permission) => PlatformRoles::allows($user, $token, $permission))));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->sessions->logout($this->staffToken($request));

        return $this->success();
    }

    private function staffToken(Request $request): PersonalAccessToken
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        abort_unless($user->is_platform_staff && $token instanceof PersonalAccessToken && $token->name === 'platform', 403);

        return $token;
    }
}
