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

        return $this->success(['id' => (string) $user->getKey(), 'username' => $user->email, 'nickname' => $user->name]);
    }

    public function menus(Request $request): JsonResponse
    {
        $token = $this->staffToken($request);
        $user = $request->user();

        $menus = [
            ['remittance-rules', '付款与到账规则', 'remittance/rules', RemittancePermission::MANAGE_RULES],
            ['remittance-operations', '运营配置', 'remittance/operations', RemittancePermission::MANAGE_RULES],
            ['remittance-dashboard', '经营仪表盘', 'remittance/dashboard', RemittancePermission::VIEW_ORDERS],
            ['remittance-quotes', '报价记录', 'remittance/quotes', RemittancePermission::MANAGE_RULES],
            ['remittance-orders', '汇款订单', 'remittance/orders', RemittancePermission::VIEW_ORDERS],
            ['remittance-reconciliation', '对账与结算', 'remittance/reconciliation', RemittancePermission::MANAGE_RECONCILIATION],
            ['remittance-materials', '补件管理', 'remittance/materials', RemittancePermission::MANAGE_MATERIALS],
            ['support-messages', '客服消息', 'support/messages', RemittancePermission::SUPPORT],
            ['support-tickets', '客服工单', 'support/tickets', RemittancePermission::SUPPORT],
            ['remittance-members', '会员中心', 'remittance/members', RemittancePermission::VIEW_MEMBERS],
            ['remittance-closures', '注销申请', 'remittance/closures', RemittancePermission::MANAGE_CLOSURES],
            ['remittance-push', 'Push 提醒', 'remittance/push', RemittancePermission::MANAGE_PUSH_PLANS],
            ['remittance-coupons', '汇款优惠券', 'remittance/coupons', RemittancePermission::MANAGE_COUPONS],
            ['remittance-staff', '员工角色', 'remittance/staff', PlatformRoles::MANAGE_ACCESS],
            ['remittance-audit', '操作审计', 'remittance/audit', PlatformRoles::MANAGE_ACCESS],
            ['remittance-status-reasons', '状态原因码', 'remittance/status-reasons', RemittancePermission::VIEW_STATUS_REASONS],
        ];

        return $this->success(array_values(array_map(
            static fn (array $menu) => [
                'id' => $menu[0], 'name' => $menu[1], 'path' => '/system/'.$menu[2], 'component' => $menu[2],
                'permission' => $menu[3], 'menuType' => 'MENU', 'visible' => true,
            ],
            array_filter($menus, static fn (array $menu) => PlatformRoles::allows($user, $token, $menu[3])),
        )));
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
