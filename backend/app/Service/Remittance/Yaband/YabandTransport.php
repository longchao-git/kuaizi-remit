<?php

namespace App\Service\Remittance\Yaband;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

#[Singleton]
final class YabandTransport
{
    /** 只接受明确配置的商户编号，用于请求及缓存隔离。 */
    public function merchantId(): string
    {
        $mid = config('yaband_remit.mid');
        if (! is_string($mid) || trim($mid) === '' || strlen($mid) > 64) {
            throw new YabandException('雅本汇款 MID 尚未配置。');
        }

        return $mid;
    }

    /** 在商户级锁内读取加密缓存，接近过期或缓存损坏时重新鉴权。 */
    public function token(): string
    {
        $mid = $this->merchantId();
        $key = $this->tokenCacheKey();

        return Cache::lock($key.':lock', 20)->block(12, function () use ($key, $mid): string {
            $cached = Cache::get($key);
            if (is_string($cached)) {
                try {
                    return Crypt::decryptString($cached);
                } catch (DecryptException) {
                    // 密钥轮换或缓存损坏时，在同一把锁内重新获取 Token。
                    Cache::forget($key);
                }
            }

            $password = config('yaband_remit.password');
            if (! is_string($password) || $password === '' || strlen($password) > 64) {
                throw new YabandException('雅本汇款 API 密码尚未配置。');
            }

            $data = $this->post('/api/auth/login', ['mid' => $mid, 'password' => $password]);
            $token = $data['token'] ?? null;
            $expiresIn = $data['expires_in'] ?? null;
            $expiresAt = $data['expires_at'] ?? null;
            $tokenType = $data['token_type'] ?? '';
            if (! is_string($token) || ! preg_match('/^[A-Za-z0-9._~+\/-]+=*$/D', $token) || ! is_int($expiresIn)
                || ! is_int($expiresAt) || ! is_string($tokenType) || strtolower($tokenType) !== 'bearer') {
                throw new YabandException('雅本汇款 Token 响应格式无效。');
            }
            if (is_array($data['merchant'] ?? null) && array_key_exists('mid', $data['merchant'])) {
                $responseMid = $data['merchant']['mid'];
                if ((! is_string($responseMid) && ! is_int($responseMid)) || (string) $responseMid !== $mid) {
                    throw new YabandException('雅本 Token 响应的商户编号不匹配。');
                }
            }

            $ttl = min($expiresIn, $expiresAt - now()->timestamp);
            if ($ttl <= 0) {
                throw new YabandException('雅本汇款返回的 Token 已过期。');
            }
            if ($ttl > 30) {
                Cache::put($key, Crypt::encryptString($token), $ttl - 30);
            }

            return $token;
        });
    }

    /** 发送已准备好的业务参数；汇率请求可保留响应小数文本供精确换算。 */
    public function post(string $path, array $payload, ?string $token = null, bool $preserveDecimals = false): array
    {
        $request = Http::asJson()->acceptJson()->connectTimeout(4)->timeout(10)->withoutRedirecting();
        if ($token !== null) {
            $request->withToken($token);
        }
        $url = $this->baseUrl().$path;

        try {
            $response = $request->post($url, $payload);
        } catch (ConnectionException) {
            throw new YabandException('无法连接雅本汇款 API。');
        }
        $raw = $response->body();
        $body = json_decode($raw, true, flags: JSON_BIGINT_AS_STRING);
        $rejected = $response->status() === 401 || ($response->status() === 200 && is_array($body)
            && ($body['status'] ?? null) !== true && in_array($body['code'] ?? null, [401, '401'], true)
            && ($body['message'] ?? null) === 'Invalid Token');
        if ($token !== null && $rejected) {
            // 只清理明确被拒绝的缓存；本次请求原样失败，支付等操作不自动重放。
            // 雅本也用HTTP200/code401返回签名错误，不能仅按业务码判断令牌过期。
            $this->forgetRejectedToken($token);
        }
        if ($preserveDecimals) {
            $body = json_decode($this->preserveDecimals($raw), true, flags: JSON_BIGINT_AS_STRING);
        }
        if (! $response->successful() || ! is_array($body) || ($body['status'] ?? null) !== true
            || ! in_array($body['code'] ?? null, [200, '200'], true) || ! is_array($body['data'] ?? null)) {
            $rawCode = is_array($body) ? ($body['code'] ?? null) : null;
            $code = filter_var($rawCode, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            throw new YabandException('雅本汇款 API 未返回成功响应。', $code, $path);
        }

        return $body['data'];
    }

    /** 用地址和商户共同隔离Bearer缓存，供获取及拒绝后的清理复用。 */
    private function tokenCacheKey(): string
    {
        return 'yaband-remit:bearer:'.hash('sha256', $this->baseUrl().'|'.$this->merchantId());
    }

    /** 锁内核对被拒令牌，迟到的401不能删除其他进程已经更新的会话。 */
    private function forgetRejectedToken(string $rejected): void
    {
        $key = $this->tokenCacheKey();
        Cache::lock($key.':lock', 20)->block(12, function () use ($key, $rejected): void {
            $cached = Cache::get($key);
            if (! is_string($cached)) {
                return;
            }
            try {
                $matches = hash_equals(Crypt::decryptString($cached), $rejected);
            } catch (DecryptException) {
                // 无法读取的缓存也没有继续使用的价值，下一次请求再鉴权。
                Cache::forget($key);

                return;
            }
            if ($matches) {
                Cache::forget($key);
            }
        });
    }

    /** 只给 JSON 字符串之外的小数数值加引号，保留整数元数据及字符串内的原始内容。 */
    private function preserveDecimals(string $json): string
    {
        if (! json_validate($json)) {
            throw new YabandException('雅本汇款响应不是有效 JSON。');
        }
        $result = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/s', function (array $match): string {
            $token = $match[0];

            return $token[0] === '"' || strpbrk($token, '.eE') === false ? $token : '"'.$token.'"';
        }, $json);
        if ($result === null) {
            throw new YabandException('雅本汇款响应无法解析。');
        }

        return $result;
    }

    /** 所有请求只发往已配置的HTTPS源站，不携带URL凭据或跟随重定向。 */
    private function baseUrl(): string
    {
        if (config('remit.channel') !== 'yaband') {
            throw new YabandException('当前环境不允许连接雅本汇款 API。');
        }
        $value = config('yaband_remit.base_url');
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            throw new YabandException('雅本汇款 API 地址尚未配置。');
        }
        $url = parse_url($value);
        if (($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) {
            throw new YabandException('雅本汇款 API 需要 HTTPS 地址。');
        }

        return rtrim($value, '/');
    }
}
