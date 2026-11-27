<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Repository\System\UserRepository;
use App\Service\System\MemberDeviceSignature;
use App\Service\System\MemberSessionService;
use App\Service\System\SmsCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class MemberSessionController extends AbstractController
{
    private const SPANISH_MOBILE = 'regex:/^\+34[67][0-9]{8}$/D';

    public function sendSms(Request $request, MemberSessionService $sessions, SmsCodeService $codes): JsonResponse
    {
        if (! $sessions->smsEnabled()) {
            return $this->smsUnavailable();
        }
        $input = $request->validate(['mobile' => ['required', 'string', self::SPANISH_MOBILE]]);
        if (! $sessions->allowsSmsMobile($input['mobile'])) {
            return $this->fail(403, '当前仅开放专用测试手机号');
        }

        try {
            $result = $codes->send($input['mobile'], $request->ip());
        } catch (RuntimeException) {
            return $this->fail(503, '短信发送失败，请稍后重试');
        }

        return $this->success($result, $sessions->isTestSmsMobile($input['mobile'])
            ? '测试验证码已准备，请输入约定测试码' : '验证码已发送');
    }

    public function loginSms(Request $request, MemberSessionService $sessions, MemberDeviceSignature $signatures): JsonResponse
    {
        if (! $sessions->smsEnabled()) {
            return $this->smsUnavailable();
        }
        $input = $request->validate([
            'mobile' => ['required', 'string', self::SPANISH_MOBILE],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'device_public_key' => $this->deviceRules($signatures),
        ]);
        $result = $sessions->loginSms($input['mobile'], $input['code'], $input['device_public_key'] ?? null);

        return $this->loginResult($result);
    }

    public function loginLocal(Request $request, MemberSessionService $sessions, MemberDeviceSignature $signatures): JsonResponse
    {
        abort_unless($sessions->localLoginEmail() !== null, 404);

        $input = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
            'device_public_key' => $this->deviceRules($signatures),
        ]);
        $result = $sessions->loginLocal($input['email'], $input['password'], $input['device_public_key'] ?? null);

        return $this->loginResult($result);
    }

    public function current(Request $request, MemberSessionService $sessions): JsonResponse
    {
        return $this->success($sessions->profile($request->user()));
    }

    public function logout(Request $request, UserRepository $users): JsonResponse
    {
        $users->revokeSession($request->user()->currentAccessToken());

        return $this->success(true);
    }

    private function deviceRules(MemberDeviceSignature $signatures): array
    {
        return ['bail', 'sometimes', 'string', 'size:88', static function (string $attribute, string $value, callable $fail) use ($signatures): void {
            if (! $signatures->isValidPublicKey($value)) {
                $fail('设备公钥无效');
            }
        }];
    }

    /** 注销账号的登录拒绝保留HTTP403，原验证码/凭据业务错误继续使用既有响应约定。 */
    private function loginResult(Result $result): JsonResponse
    {
        return ResponseResult::json($result, $result->getCode() === 403 ? 403 : 200);
    }

    /** 保持未开放端点的 HTTP 状态，并让 App 显示可理解的原因。 */
    private function smsUnavailable(): JsonResponse
    {
        return ResponseResult::json(Result::fail('短信登录暂未开放', 404), 404);
    }
}
