<?php

namespace App\Controller\Common;

use App\Common\ResponseResult;
use App\Common\Result;
use App\Controller\AbstractController;
use App\Repository\System\UserRepository;
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

    public function loginSms(Request $request, MemberSessionService $sessions): JsonResponse
    {
        if (! $sessions->smsEnabled()) {
            return $this->smsUnavailable();
        }
        $input = $request->validate([
            'mobile' => ['required', 'string', self::SPANISH_MOBILE],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
        ]);
        $result = $sessions->loginSms($input['mobile'], $input['code']);

        return $result->isSuccess() ? $this->success($result->getData(), $result->getMsg())
            : $this->fail($result->getCode(), $result->getMsg());
    }

    public function loginLocal(Request $request, MemberSessionService $sessions): JsonResponse
    {
        abort_unless($sessions->localLoginEmail() !== null, 404);

        $input = $request->validate([
            'email' => ['required', 'email:rfc', 'max:254'],
            'password' => ['required', 'string', 'max:72'],
        ]);
        $result = $sessions->loginLocal($input['email'], $input['password']);

        return $result->isSuccess()
            ? $this->success($result->getData(), $result->getMsg())
            : $this->fail($result->getCode(), $result->getMsg());
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

    /** 保持未开放端点的 HTTP 状态，并让 App 显示可理解的原因。 */
    private function smsUnavailable(): JsonResponse
    {
        return ResponseResult::json(Result::fail('短信登录暂未开放', 404), 404);
    }
}
