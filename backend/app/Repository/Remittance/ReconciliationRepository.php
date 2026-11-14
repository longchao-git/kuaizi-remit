<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\ReconciliationBatch;
use App\Model\Remittance\ReconciliationLine;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/** @extends AbstractRepository<ReconciliationBatch> */
final class ReconciliationRepository extends AbstractRepository
{
    protected function model(): ReconciliationBatch
    {
        return new ReconciliationBatch;
    }

    public function page(int $page, ?string $date, ?string $reference, ?string $status): LengthAwarePaginator
    {
        $pending = $this->lines()->whereColumn('batch_id', 'remittance_reconciliation_batches.id')
            ->where('result', '!=', 'matched')->whereNull('reviewed_at');
        $query = $this->query()
            ->addSelect([
                'line_count' => $this->lines()->selectRaw('COUNT(*)')->whereColumn('batch_id', 'remittance_reconciliation_batches.id'),
                'difference_count' => $this->lines()->selectRaw('COUNT(*)')->whereColumn('batch_id', 'remittance_reconciliation_batches.id')
                    ->where('result', '!=', 'matched'),
                'pending_count' => (clone $pending)->selectRaw('COUNT(*)'),
            ])
            ->when($date !== null, fn (Builder $query) => $query->whereDate('settlement_date', $date))
            ->when($reference !== null, fn (Builder $query) => $query->whereIn('id',
                $this->lines()->select('batch_id')->where('order_reference', $reference)));
        if ($status !== null) {
            $query->whereExists($pending->selectRaw('1')->toBase(), not: $status === 'reviewed');
        }

        return $query->orderByDesc('id')->paginate(20, page: $page);
    }

    public function createOnce(string $digest, array $input): ReconciliationBatch
    {
        return $this->query()->createOrFirst(['digest' => $digest], $input);
    }

    public function insertLines(array $rows): void
    {
        foreach (array_chunk($rows, 100) as $chunk) {
            if (! $this->lines()->insert($chunk)) {
                throw new RuntimeException('未能保存对账明细');
            }
        }
    }

    public function exists(int $batch): bool
    {
        return $this->query()->whereKey($batch)->exists();
    }

    public function linePage(int $batch, int $page, string $filter): LengthAwarePaginator
    {
        $query = $this->lines()->where('batch_id', $batch)->orderBy('id');
        match ($filter) {
            'differences' => $query->where('result', '!=', 'matched'),
            'pending' => $query->where('result', '!=', 'matched')->whereNull('reviewed_at'),
            default => null,
        };

        return $query->paginate(30, page: $page);
    }

    public function batchLines(int $batch): Collection
    {
        return $this->lines()->where('batch_id', $batch)->orderBy('id')->get();
    }

    public function lockLine(int $batch, int $line): ?ReconciliationLine
    {
        return $this->lines()->where('batch_id', $batch)->lockForUpdate()->find($line);
    }

    public function review(ReconciliationLine $line, int $staff, string $note): void
    {
        $line->forceFill(['reviewed_by' => $staff, 'review_note' => $note, 'reviewed_at' => now()])->saveOrFail();
    }

    /** @return Builder<ReconciliationLine> */
    private function lines(): Builder
    {
        return ReconciliationLine::query();
    }
}
