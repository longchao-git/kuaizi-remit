<?php

namespace App\Service\Remittance;

use App\Repository\Remittance\RemittanceOrderRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/** 对漏收通知的未完成订单执行有限批次的主动查询。 */
#[Singleton]
final class RemittanceOrderSyncService
{
    /** 复用订单服务的归属校验、锁及幂等状态同步。 */
    public function __construct(
        private RemittanceOrderRepository $orders,
        private RemittanceOrderService $service,
    ) {}

    /**
     * 查询失败保留尝试时间以便退避，不影响本批其他订单。
     *
     * @return array{selected: int, synced: int, failed: int, skipped: int}
     */
    public function run(mixed $limit = null): array
    {
        $batchSize = $this->boundedInteger($limit ?? config('yaband_remit.order_sync.batch_size'), 1, 100, '批量上限');
        $interval = $this->boundedInteger(config('yaband_remit.order_sync.interval_seconds'), 60, 86400, '查询间隔（秒）');
        $before = now()->subSeconds($interval);
        $candidates = $this->orders->dueForSync($before, $batchSize);
        $counts = ['selected' => $candidates->count(), 'synced' => 0, 'failed' => 0, 'skipped' => 0];

        foreach ($candidates as $order) {
            $result = $this->service->refreshIfDue($order->reference, $before);
            if ($result === null) {
                $counts['skipped']++;
            } elseif ($result->isSuccess()) {
                $counts['synced']++;
            } else {
                $counts['failed']++;
                Log::warning('雅本订单补偿查询失败', ['reference' => $order->reference, 'code' => $result->getCode()]);
            }
        }

        return $counts;
    }

    /** 拒绝错误配置和非整数参数，防止无间隔查询或无限批次。 */
    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        $integer = (is_int($value) || is_string($value))
            ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => $minimum, 'max_range' => $maximum]]) : false;
        if ($integer === false) {
            throw new InvalidArgumentException("订单同步{$label}必须是 {$minimum}–{$maximum} 的整数。");
        }

        return $integer;
    }
}
