<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\YabandMaterialRequest;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/** @extends AbstractRepository<YabandMaterialRequest> */
final class YabandMaterialRequestRepository extends AbstractRepository
{
    protected function model(): YabandMaterialRequest
    {
        return new YabandMaterialRequest;
    }

    public function page(int $page, ?int $member, array $filters = []): LengthAwarePaginator
    {
        return $this->query()->when($member !== null, fn ($query) => $query->where('member_id', $member))
            ->when(isset($filters['member_id']), fn ($query) => $query->where('member_id', $filters['member_id']))
            ->when(isset($filters['question_id']), fn ($query) => $query->where('question_id', $filters['question_id']))
            ->when(isset($filters['status']), function ($query) use ($filters): void {
                if ($filters['status'] === '6003') {
                    $query->where(fn ($state) => $state->where('status', '6003')
                        ->orWhere(fn ($expired) => $expired->where('status', '6001')->where('deadline_at', '<=', now())));
                } else {
                    $query->where('status', $filters['status']);
                    if ($filters['status'] === '6001') {
                        $query->where('deadline_at', '>', now());
                    }
                }
            })
            ->orderByDesc('created_at')->orderByDesc('reference')->paginate(20, page: $page);
    }

    public function findForStaff(string $reference): ?YabandMaterialRequest
    {
        return $this->query()->find($reference);
    }

    public function find(string $reference, int $member): ?YabandMaterialRequest
    {
        return $this->query()->where('member_id', $member)->find($reference);
    }

    public function register(string $question, array $fields): YabandMaterialRequest
    {
        return $this->query()->firstOrCreate(['question_id' => $question], ['reference' => (string) Str::uuid(), ...$fields]);
    }

    public function reserve(YabandMaterialRequest $task, string $hash): void
    {
        $task->forceFill(['submission_id' => (string) Str::uuid(), 'submission_hash' => $hash])->saveOrFail();
    }

    public function saveStatus(YabandMaterialRequest $task, string $status, bool $submissionConfirmed): void
    {
        $task->forceFill([
            'status' => $status,
            'submission_hash' => $submissionConfirmed ? $task->submission_hash : null,
        ])->saveOrFail();
    }
}
