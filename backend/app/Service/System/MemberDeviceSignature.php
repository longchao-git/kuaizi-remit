<?php

namespace App\Service\System;

use App\Common\Result;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\PersonalAccessToken;
use OpenSSLAsymmetricKey;
use Throwable;

/** 校验从通过凭据验证的会员会话绑定的 P-256 请求签名。 */
final class MemberDeviceSignature
{
    /** 固定 X9.63 未压缩公开点的 SPKI 前缀为 id-ecPublicKey 和 prime256v1。 */
    private const PUBLIC_KEY_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private const WINDOW_SECONDS = 90;

    public function isValidPublicKey(string $encoded): bool
    {
        return $this->key($encoded) !== null;
    }

    /** 只覆盖资金和身份写入路径，旧别名同样启用；签名保留实际 URL 区别。 */
    public function requiresProof(Request $request): bool
    {
        if (config('remit.require_device_signatures') !== true) {
            return false;
        }
        $path = preg_replace('#^api/#', '', $request->path());

        return ($request->isMethod('POST') && in_array($path, ['app/kyc/idv', 'app/kyc/profile', 'app/remittance/orders'], true))
            || ($request->isMethod('PUT') && preg_match('#\Aapp/remittance/payout-account-drafts/[a-fA-F0-9-]{36}\z#', $path) === 1);
    }

    public function verify(Request $request, PersonalAccessToken $token): Result
    {
        $bearer = $request->bearerToken();
        $public = $token->getAttribute('signing_public_key');
        $time = $request->header('X-Remit-Time');
        $nonce = $request->header('X-Remit-Nonce');
        $encoded = $request->header('X-Remit-Signature');
        if (! is_string($bearer) || ! is_string($public) || ! is_string($time) || ! preg_match('/\A[0-9]{10}\z/', $time)
            || abs(now()->timestamp - (int) $time) > self::WINDOW_SECONDS
            || ! is_string($nonce) || ! preg_match('/\A[a-f0-9]{32}\z/', $nonce)
            || ! is_string($encoded) || strlen($encoded) > 100 || ! $request->isJson() || $request->getQueryString() !== null) {
            return Result::fail('请求签名缺失或已过期，请重新登录或更新 App', 403);
        }

        $body = $request->getContent();
        $signature = base64_decode($encoded, true);
        $key = $this->key($public);
        if (strlen($body) > 65536 || $signature === false || base64_encode($signature) !== $encoded || $key === null) {
            return Result::fail('请求签名无效', 403);
        }
        $message = implode("\n", [
            'remit-v1', $request->getMethod(), $request->getPathInfo(), hash('sha256', $body),
            hash('sha256', $bearer), $time, $nonce,
        ]);
        if (openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            return Result::fail('请求签名无效', 403);
        }

        // 只有已验签的请求才占用随机值。生产使用共享的原子缓存，保证跨实例只执行一次。
        if (! app()->isLocal() && ! app()->runningUnitTests() && ! in_array(config('cache.default'), ['database', 'redis'], true)) {
            return Result::fail('请求验证暂不可用', 503);
        }
        try {
            $replayKey = 'remit:nonce:'.hash('sha256', $token->getKey().'|'.$nonce);
            $accepted = Cache::add($replayKey, true, self::WINDOW_SECONDS * 2 + 5);
        } catch (Throwable) {
            return Result::fail('请求验证暂不可用', 503);
        }

        return $accepted ? Result::success() : Result::fail('请求已使用，请重新发起操作', 409);
    }

    private function key(string $encoded): ?OpenSSLAsymmetricKey
    {
        if (strlen($encoded) !== 88) {
            return null;
        }
        $point = base64_decode($encoded, true);
        if (! is_string($point) || strlen($point) !== 65 || $point[0] !== "\x04" || base64_encode($point) !== $encoded) {
            return null;
        }
        $der = hex2bin(self::PUBLIC_KEY_PREFIX).$point;
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }
}
