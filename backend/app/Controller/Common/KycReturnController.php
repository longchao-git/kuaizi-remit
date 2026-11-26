<?php

namespace App\Controller\Common;

use Illuminate\Http\Response;

/** 外部认证页返回的公开落地页，不采信URL参数或展示任何个人认证结果。 */
final class KycReturnController
{
    /** 仅引导用户回到App查询，不发起上游请求、更新状态或设置登录会话。 */
    public function __invoke(): Response
    {
        return response()->view('remittance.kyc-return', [], 200, [
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ]);
    }
}
