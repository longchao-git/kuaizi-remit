<?php

namespace App\Service\Remittance\Yaband;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** 缓存雅本参考汇率，用整数万分位为现有报价试算提供输入。 */
#[Singleton]
final class YabandReferenceRateService
{
    private const CACHE_SECONDS = 60;

    /** 复用雅本签名、鉴权及商户响应核对。 */
    public function __construct(private YabandRemitClient $client) {}

    /**
     * 只返回有效参考值及实际观察时间；缓存失效或查询失败时不返回旧值。
     *
     * @return array{rate_ten_thousandths: int, observed_at: int}
     */
    public function current(): array
    {
        $identity = rtrim((string) config('yaband_remit.base_url'), '/').'|'.config('yaband_remit.mid');
        $key = 'yaband-remit:reference-rate:'.hash('sha256', $identity);

        return Cache::lock($key.':lock', 45)->block(3, function () use ($key): array {
            $cached = Cache::get($key);
            if (is_array($cached) && is_int($cached['rate_ten_thousandths'] ?? null)
                && $cached['rate_ten_thousandths'] >= 1 && $cached['rate_ten_thousandths'] <= 1000000
                && is_int($cached['observed_at'] ?? null) && $cached['observed_at'] <= now()->timestamp
                && $cached['observed_at'] > now()->timestamp - self::CACHE_SECONDS) {
                return $cached;
            }
            Cache::forget($key);

            $rate = $this->quantize($this->client->queryReferenceRate((string) Str::uuid()));
            $result = ['rate_ten_thousandths' => $rate, 'observed_at' => now()->timestamp];
            Cache::put($key, $result, self::CACHE_SECONDS);

            return $result;
        });
    }

    /** 沿用项目万分位精度并四舍五入，直接处理十进制字符，不经浮点运算。 */
    private function quantize(string $rate): int
    {
        if (strlen($rate) > 256 || ! preg_match('/^(0|[1-9][0-9]{0,2})(?:\.([0-9]+))?$/D', $rate, $parts)) {
            throw new YabandException('雅本参考汇率不是有效的十进制数值。');
        }
        $fraction = str_pad($parts[2] ?? '', 5, '0');
        $scaled = (int) $parts[1] * 10000 + (int) substr($fraction, 0, 4) + ($fraction[4] >= '5' ? 1 : 0);
        if ($scaled < 1 || $scaled > 1000000) {
            throw new YabandException('雅本参考汇率超出当前报价范围。');
        }

        return $scaled;
    }
}
