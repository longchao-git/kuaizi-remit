<?php

namespace App\Controller\Common;

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
        abort_unless($sessions->localSmsEnabled(), 404);
        $input = $request->validate(['mobile' => ['required', 'string', self::SPANISH_MOBILE]]);

        try {
            $result = $codes->send($input['mobile'], $request->ip());
        } catch (RuntimeException) {
            return $this->fail(503, '短信发送失败，请稍后重试');
        }

        return $this->success($result, '验证码已发送');
    }

    public function loginSms(Request $request, MemberSessionService $sessions): JsonResponse
    {
        abort_unless($sessions->localSmsEnabled(), 404);
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
}
