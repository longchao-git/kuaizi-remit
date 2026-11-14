<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\ReconciliationBatch;
use App\Model\Remittance\ReconciliationLine;
use App\Model\Remittance\RemittanceOrder;
use App\Repository\Remittance\ReconciliationRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

#[Singleton]
final class ReconciliationService
{
    public function __construct(
        private ReconciliationRepository $batches,
        private RemittanceOrderRepository $orders,
        private ReconciliationCsv $csv,
        private DatabaseManager $database,
    ) {}

    public function page(int $page, ?string $date, ?string $reference, ?string $status): Result
    {
        $rows = $this->batches->page($page, $date, $reference === null ? null : strtolower($reference), $status);

        return $this->pageResult($rows, array_map($this->formatBatch(...), $rows->items()));
    }

    public function lines(int $batch, int $page, string $filter): Result
    {
        if (! $this->batches->exists($batch)) {
            return Result::fail('对账批次不存在', 404);
        }
        $rows = $this->batches->linePage($batch, $page, $filter);

        return $this->pageResult($rows, array_map($this->formatLine(...), $rows->items()));
    }

    /** 一次保存批次及快照。同一结算日的同一组明细复用原批次。 */
    public function import(string $contents, string $date, string $filename, int $staff): Result
    {
        try {
            $entries = $this->csv->parse($contents);
        } catch (InvalidArgumentException $error) {
            return Result::fail($error->getMessage(), 422);
        }
        $digest = hash('sha256', json_encode([$date, $entries], JSON_THROW_ON_ERROR));

        return $this->database->transaction(function () use ($entries, $digest, $date, $filename, $staff): Result {
            $batch = $this->batches->createOnce($digest, [
                'settlement_date' => $date, 'filename' => $filename, 'imported_by' => $staff,
            ]);
            $created = $batch->wasRecentlyCreated;
            if ($created) {
                $orders = $this->orders->forReconciliation(array_column($entries, 'order_reference'));
                $lines = array_map(fn (array $entry) => $this->matchLine($batch->id, $entry, $orders->get($entry['order_reference'])), $entries);
                $this->batches->insertLines($lines);
            }

            return Result::success(['id' => (string) $batch->id, 'reused' => ! $created], $created ? '对账批次已导入' : '已打开原对账批次');
        }, attempts: 3);
    }

    /** 记录复核结论并保留导入时的差异。 */
    public function review(int $batch, int $line, int $staff, string $note): Result
    {
        return $this->database->transaction(function () use ($batch, $line, $staff, $note): Result {
            $row = $this->batches->lockLine($batch, $line);
            if ($row === null) {
                return Result::fail('该批次没有此明细', 404);
            }
            if ($row->result === 'matched' || $row->reviewed_at !== null) {
                return Result::fail('该明细无需复核或已复核，请刷新', 409);
            }
            $this->batches->review($row, $staff, $note);

            return Result::success($this->formatLine($row), '复核已记录');
        }, attempts: 3);
    }

    public function export(int $batch): Result
    {
        if (! $this->batches->exists($batch)) {
            return Result::fail('对账批次不存在', 404);
        }

        return Result::success($this->batches->batchLines($batch)->map($this->formatLine(...))->all());
    }

    private function matchLine(int $batch, array $entry, ?RemittanceOrder $order): array
    {
        $amount = $order?->principal_minor;
        $status = $order === null ? null : $this->orderStatus($order);
        $amountDiffers = $amount !== $entry['imported_minor'];
        $statusDiffers = $entry['imported_status'] !== null && $status !== $entry['imported_status'];

        return [
            'batch_id' => $batch, ...$entry, 'expected_minor' => $amount, 'expected_status' => $status,
            'result' => match (true) {
                $order === null => 'missing',
                $amountDiffers && $statusDiffers => 'amount_status',
                $amountDiffers => 'amount',
                $statusDiffers => 'status',
                default => 'matched',
            },
        ];
    }

    private function orderStatus(RemittanceOrder $order): string
    {
        return match (true) {
            $order->refund_code === '04' => 'refunded',
            in_array($order->refund_code, ['01', '02', '03'], true) => 'refunding',
            in_array($order->refund_code, ['05', '06'], true) => 'refund_failed',
            $order->refund_code !== '00' => 'unknown',
            $order->income_code === '00' && $order->outgoing_code === '05' => 'paid_out',
            $order->income_code === '00' && in_array($order->outgoing_code, ['03', '06', '07'], true) => 'failed',
            $order->income_code === '00' => 'paid',
            in_array($order->income_code, ['05', '06', '07', '08'], true) => 'failed',
            in_array($order->income_code, ['01', '02', '03', '04'], true) => 'pending',
            default => 'unknown',
        };
    }

    private function formatBatch(ReconciliationBatch $batch): array
    {
        $total = (int) $batch->getAttribute('line_count');
        $differences = (int) $batch->getAttribute('difference_count');

        return [
            'id' => (string) $batch->id,
            'settlement_date' => $batch->settlement_date->toDateString(),
            'filename' => $batch->filename,
            'imported_by' => $batch->imported_by === null ? null : (string) $batch->imported_by,
            'created_at' => $batch->created_at->toISOString(),
            'line_count' => $total,
            'matched_count' => $total - $differences,
            'difference_count' => $differences,
            'pending_count' => (int) $batch->getAttribute('pending_count'),
        ];
    }

    private function formatLine(ReconciliationLine $row): array
    {
        return [
            'id' => (string) $row->id,
            'order_reference' => $row->order_reference,
            'imported_minor' => $row->imported_minor,
            'expected_minor' => $row->expected_minor,
            'imported_status' => $row->imported_status,
            'expected_status' => $row->expected_status,
            'result' => $row->result,
            'reviewed_by' => $row->reviewed_by === null ? null : (string) $row->reviewed_by,
            'review_note' => $row->review_note,
            'reviewed_at' => $row->reviewed_at?->toISOString(),
        ];
    }

    private function pageResult(LengthAwarePaginator $rows, array $items): Result
    {
        return Result::success(PaginationPayload::from($items, $rows->total(), $rows->currentPage(), $rows->perPage())->toArray());
    }
}
