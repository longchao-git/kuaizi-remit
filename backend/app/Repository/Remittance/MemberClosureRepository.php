<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\MemberClosureRequest;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** @extends AbstractRepository<MemberClosureRequest> */
final class MemberClosureRepository extends AbstractRepository
{
    protected function model(): MemberClosureRequest
    {
        return new MemberClosureRequest;
    }

    public function latest(int $member): ?MemberClosureRequest
    {
        return $this->query()->where('member_id', $member)->orderByDesc('id')->first();
    }

    public function approved(int $member): bool
    {
        return $this->query()->where('member_id', $member)->where('status', 'approved')->exists();
    }

    public function page(int $page, ?int $member, ?string $status): LengthAwarePaginator
    {
        return $this->query()->when($member, fn ($query) => $query->where('member_id', $member))
            ->when($status, fn ($query) => $query->where('status', $status))
            ->orderByDesc('id')->paginate(20, page: $page);
    }

    public function create(int $member, ?string $reason): MemberClosureRequest
    {
        return $this->query()->create(['member_id' => $member, 'reason' => $reason, 'status' => 'pending']);
    }

    public function lock(int $id): ?MemberClosureRequest
    {
        return $this->query()->lockForUpdate()->find($id);
    }

    public function review(MemberClosureRequest $row, int $staff, string $decision, string $note): void
    {
        $row->forceFill([
            'status' => $decision, 'reviewed_by' => $staff, 'review_note' => $note, 'reviewed_at' => now(),
        ])->saveOrFail();
    }
}
