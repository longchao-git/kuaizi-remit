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
    public function merchantId(): string
    {
        $mid = config('yaband_remit.mid');
        if (! is_string($mid) || trim($mid) === '' || strlen($mid) > 64) {
            throw new YabandException('雅本汇款 MID 尚未配置。');
        }

        return $mid;
    }

    public function token(): string
    {
        $mid = $this->merchantId();
        $key = 'yaband-remit:bearer:'.hash('sha256', $this->baseUrl().'|'.$mid);

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

    public function post(string $path, array $payload, ?string $token = null): array
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
        $body = $response->json(flags: JSON_BIGINT_AS_STRING);
        if (! $response->successful() || ! is_array($body) || ($body['status'] ?? null) !== true
            || ! in_array($body['code'] ?? null, [200, '200'], true) || ! is_array($body['data'] ?? null)) {
            $rawCode = is_array($body) ? ($body['code'] ?? null) : null;
            $code = filter_var($rawCode, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE);
            throw new YabandException('雅本汇款 API 未返回成功响应。', $code);
        }

        return $body['data'];
    }

    private function baseUrl(): string
    {
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
