<?php

namespace App\Console\Commands;

use App\Service\Remittance\RemittanceOrderSyncService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** 仅查询已有交易，不创建支付或执行资金操作。 */
final class SyncRemittanceOrders extends Command
{
    protected $signature = 'remit:sync-orders {--limit= : 本批最多查询的订单数（1–100）}';

    protected $description = '补偿查询漏收通知的未完成雅本订单';

    public function handle(RemittanceOrderSyncService $sync): int
    {
        try {
            $counts = $sync->run($this->option('limit'));
        } catch (InvalidArgumentException $error) {
            $this->error($error->getMessage());

            return self::INVALID;
        }

        $this->info("本批选中 {$counts['selected']} 笔：同步成功 {$counts['synced']} 笔，失败 {$counts['failed']} 笔，跳过 {$counts['skipped']} 笔。");

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
