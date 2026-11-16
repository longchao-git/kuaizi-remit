<?php

namespace App\Service\Remittance;

use App\Common\Result;
use App\Model\Remittance\RemittanceOrder;
use App\Repository\Remittance\RemittanceOrderRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final class RemittanceDashboardService
{
    public function __construct(private RemittanceOrderRepository $orders, private RemittanceOrderService $orderService) {}

    /** 按马德里创建日展示订单数与这些订单的当前状态。 */
    public function summary(int $days): Result
    {
        $today = now('Europe/Madrid')->startOfDay();
        $start = $today->copy()->subDays($days - 1);
        $end = $today->copy()->addDay();
        $timezone = config('app.timezone');
        $windows = [];
        for ($day = $start->copy(); $day->lt($end); $day->addDay()) {
            $windows[$day->toDateString()] = [
                $day->copy()->timezone($timezone),
                $day->copy()->addDay()->timezone($timezone),
            ];
        }
        $daily = $this->orders->dashboardDailyMetrics($windows);

        return Result::success([
            'start_date' => $start->toDateString(),
            'end_date' => $today->toDateString(),
            'timezone' => $today->timezoneName,
            'metrics' => [
                'order_count' => $daily->sum('order_count'),
                'paid_count' => $daily->sum('paid_count'),
                'paid_principal_minor' => $daily->sum('paid_principal_minor'),
                'paid_out_count' => $daily->sum('paid_out_count'),
                'refunded_count' => $daily->sum('refunded_count'),
            ],
            'trend' => array_map(static fn (string $date): array => [
                'date' => $date,
                'order_count' => (int) ($daily->get($date)?->order_count ?? 0),
                'paid_out_count' => (int) ($daily->get($date)?->paid_out_count ?? 0),
            ], array_keys($windows)),
            'exceptions' => [
                'payment_failed_count' => $daily->sum('payment_failed_count'),
                'payout_failed_count' => $daily->sum('payout_failed_count'),
                'refund_failed_count' => $daily->sum('refund_failed_count'),
            ],
            'recent' => $this->orders->dashboardRecent($start->copy()->timezone($timezone), $end->copy()->timezone($timezone))->map(fn (RemittanceOrder $row) => [
                'reference' => $row->reference,
                'principal_minor' => $row->principal_minor,
                'status_label' => $this->orderService->statusLabel($row),
                'created_at' => $row->created_at->toISOString(),
            ])->all(),
        ]);
    }
}
