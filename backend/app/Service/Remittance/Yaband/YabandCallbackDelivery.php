<?php

namespace App\Service\Remittance\Yaband;

use App\Common\Result;
use App\Service\Remittance\RemittanceChannels;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/** 只接受供应商配置的来源，并对验签成功的同一通知合并处理。 */
final readonly class YabandCallbackDelivery
{
    public function __construct(private RemittanceChannels $channels) {}

    public function allowsSource(?string $sourceIp): bool
    {
        if (config('remit.channel') !== 'yaband') {
            return false;
        }
        $configured = config('yaband_remit.callback_source_ips');
        if (! is_string($configured)) {
            return false;
        }
        $allowed = array_values(array_filter(array_map('trim', explode(',', $configured)), static fn (string $ip): bool => $ip !== ''));
        if ($allowed === []) {
            return config('yaband_remit.callback_ip_required') === false && config('yaband_remit.environment') === 'sandbox';
        }
        foreach ($allowed as $entry) {
            [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);
            if (filter_var($address, FILTER_VALIDATE_IP) === false
                || ($bits !== null && (! ctype_digit($bits) || (int) $bits < 1 || (int) $bits > (str_contains($address, ':') ? 128 : 32)))) {
                return false;
            }
        }

        return $sourceIp !== null && IpUtils::checkIp($sourceIp, $allowed);
    }

    /** 仅保存成功通知的签名摘要，失败或竞争中的通知仍须由上游重试。 */
    public function process(string $event, string $verifiedSignature, callable $handle): Result
    {
        $fingerprint = hash('sha256', $this->channels->yabandScope().'|'.$event.'|'.$verifiedSignature);
        $key = 'remit:callback:'.$fingerprint;
        $lock = Cache::lock($key.':lock', 75);
        if (! $lock->get()) {
            return Result::fail('通知正在处理，请稍后重试', 409);
        }
        try {
            if (Cache::has($key)) {
                return Result::success();
            }
            $result = $handle();
            if ($result->isSuccess()) {
                // 缓存只压缩同一签名的近期重试，长期正确性仍由订单状态及补件幂等保证。
                Cache::put($key, true, now()->addMinutes(15));
            }

            return $result;
        } finally {
            $lock->release();
        }
    }
}
