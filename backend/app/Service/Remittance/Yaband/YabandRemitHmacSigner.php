<?php

namespace App\Service\Remittance\Yaband;

use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use JsonException;
use stdClass;

/** 按雅本汇款请求规范生成 HMAC，保留嵌套 JSON 的输入顺序。 */
final readonly class YabandRemitHmacSigner implements YabandRemitSigner
{
    /** 密钥仅用于服务端签名，不进入错误信息。 */
    public function __construct(private string $secretKey)
    {
        if ($secretKey === '') {
            throw new YabandException('雅本请求签名密钥尚未配置。');
        }
    }

    /** 请求路径由调用接口保留，协议仅对规定的请求参数签名。 */
    public function sign(string $path, array $payload): string
    {
        $data = $payload['data'] ?? [];
        if ($data instanceof stdClass) {
            $data = (array) $data;
        }
        if (! is_array($data)) {
            throw new YabandException('雅本请求签名的 data 格式无效。');
        }
        unset($payload['sign'], $payload['data']);
        if (! empty($payload['s'])) {
            unset($payload['s']);
        }

        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $data[$key] = $value ? 'true' : 'false';
            } elseif (is_array($value) || is_object($value)) {
                try {
                    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    throw new YabandException('雅本请求参数无法序列化为签名数据。');
                }
                // 协议要求替换对象属性 null，数组中的独立 null 保留。
                $data[$key] = str_replace(':null', ':""', $json);
            }
        }

        $parameters = array_replace($data, $payload);
        ksort($parameters);
        $pairs = [];
        foreach ($parameters as $key => $value) {
            if ($value !== null && ! is_scalar($value)) {
                throw new YabandException('雅本请求签名的顶层参数格式无效。');
            }
            $pairs[] = $key.'='.(string) $value;
        }

        return hash_hmac('sha256', implode('&', $pairs), $this->secretKey);
    }
}
