<?php

namespace App\Service\Remittance\Yaband;

use App\Service\Remittance\Yaband\Contract\YabandRemitCallbackVerifier;
use JsonException;

/** 按雅本PHP Demo的通知算法验签，独立于API请求签名，不直接采用通知中的业务状态。 */
final readonly class YabandRemitHmacCallbackVerifier implements YabandRemitCallbackVerifier
{
    /** 只有显式开启通知且商户与密钥齐备时由容器注册，不在错误中输出配置。 */
    public function __construct(private string $secretKey, private string $merchantId)
    {
        if (trim($secretKey) === '' || trim($merchantId) === '') {
            throw new YabandException('雅本通知验签配置不完整。');
        }
    }

    /** 基于原始JSON重新解析并核对调用方数据，封装或平铺通知均只使用已签的业务字段。 */
    public function verifies(string $event, string $body, array $payload): bool
    {
        if (! in_array($event, ['kyc', 'transaction'], true) || strlen($body) > 262144) {
            return false;
        }
        try {
            // 与HTTP入参及Demo的数组输入一致；不把嵌套对象折叠为{}，也不修剪JSON字符串。
            $decoded = json_decode($body, true, 64, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || $decoded !== $payload) {
                return false;
            }
            $signature = $decoded['sign'] ?? null;
            if (! is_string($signature) || preg_match('/\A[a-f0-9]{64}\z/', $signature) !== 1) {
                return false;
            }
            $data = array_key_exists('data', $decoded) ? $decoded['data'] : $decoded;
            if (! is_array($data) || ($data['mid'] ?? null) !== $this->merchantId || ($data['type'] ?? null) !== $event) {
                return false;
            }
            unset($data['sign']);
            ksort($data);
            $pairs = [];
            foreach ($data as $key => $value) {
                if (is_array($value)) {
                    // 通知采用默认JSON转义并保留null，不能复用请求签名的不转义/替换null规则。
                    $value = json_encode($value, JSON_THROW_ON_ERROR);
                } elseif (is_bool($value)) {
                    $value = $value ? 'true' : 'false';
                }
                $pairs[] = $key.'='.(string) $value;
            }

            return hash_equals(hash_hmac('sha256', implode('&', $pairs), $this->secretKey), $signature);
        } catch (JsonException) {
            return false;
        }
    }
}
