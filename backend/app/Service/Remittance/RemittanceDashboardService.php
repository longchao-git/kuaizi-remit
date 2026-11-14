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

    public function summary(int $days): Result
    {
        $today = now('Europe/Madrid')->startOfDay();
        $start = $today->copy()->subDays($days - 1);
        $end = $today->copy()->addDay();
        $databaseStart = $start->copy()->timezone(config('app.timezone'));
        $databaseEnd = $end->copy()->timezone(config('app.timezone'));

        return Result::success([
            'start_date' => $start->toDateString(),
            'end_date' => $today->toDateString(),
            'timezone' => $today->timezoneName,
            'metrics' => $this->orders->dashboardMetrics($databaseStart, $databaseEnd),
            'recent' => $this->orders->dashboardRecent($databaseStart, $databaseEnd)->map(fn (RemittanceOrder $row) => [
                'reference' => $row->reference,
                'principal_minor' => $row->principal_minor,
                'status_label' => $this->orderService->statusLabel($row),
                'created_at' => $row->created_at->toISOString(),
            ])->all(),
        ]);
    }
}
