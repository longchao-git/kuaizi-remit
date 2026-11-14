<?php

namespace App\Controller\Common;

use App\Service\Remittance\RemittanceOrderService;
use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use App\Service\Remittance\Yaband\MemberKycService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** 已验签的通知触发主动查单。敏感字段和回调原文不写入业务表。 */
final class YabandRemitCallbackController
{
    public function __invoke(Request $request, string $event, RemittanceOrderService $orders, MemberKycService $kyc): Response
    {
        abort_unless(app()->bound(YabandRemitCallbackVerifier::class), 503);
        abort_unless($request->isJson(), 415);
        $raw = $request->getContent();
        abort_if(strlen($raw) > 262144, 413);
        $payload = json_decode($raw, true, 64, JSON_BIGINT_AS_STRING);
        abort_unless(is_array($payload) && is_string($payload['sign'] ?? null) && $payload['sign'] !== '', 422);
        abort_unless(app(YabandRemitCallbackVerifier::class)->verifies($event, $raw, $payload), 403);

        $data = $payload['data'] ?? null;
        $mid = config('yaband_remit.mid');
        abort_unless(is_array($data) && is_string($mid) && $mid !== '' && ($data['mid'] ?? null) === $mid, 403);
        if ($event === 'transaction') {
            $order = $data['order_id'] ?? null;
            $trade = $data['trade_id'] ?? null;
            abort_unless(($data['type'] ?? null) === 'transaction' && is_string($order) && is_string($trade), 422);
            $result = $orders->refreshNotified($order, $trade);
        } else {
            $user = is_string($data['user'] ?? null) ? json_decode($data['user'], true, 16, JSON_BIGINT_AS_STRING) : null;
            $uid = is_array($user) ? ($user['uid'] ?? null) : null;
            abort_unless(($data['type'] ?? null) === 'kyc' && (is_string($uid) || is_int($uid))
                && $uid !== '' && strlen((string) $uid) <= 64, 422);
            $result = $kyc->refreshNotified((string) $uid);
        }

        abort_if($result->isFail(), $result->getCode());

        return response('ok', 200, ['Content-Type' => 'text/plain']);
    }
}
