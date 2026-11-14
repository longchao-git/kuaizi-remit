<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\YabandRemitMember;
use App\Repository\AbstractRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;

/** @extends AbstractRepository<YabandRemitMember> */
final class YabandRemitMemberRepository extends AbstractRepository
{
    protected function model(): YabandRemitMember
    {
        return new YabandRemitMember;
    }

    public function findFor(int $member): ?YabandRemitMember
    {
        return $this->query()->where('member_id', $member)->first();
    }

    public function findByUid(string $uid): ?YabandRemitMember
    {
        return $this->query()->where('uid', $uid)->first();
    }

    /** 一次读取当前后台分页中的会员绑定。 */
    public function forMembers(array $members): Collection
    {
        return $this->query()->whereIn('member_id', $members)->get()->keyBy('member_id');
    }

    public function eligibleForFirstTransferReminder(Builder $alreadySent): LazyCollection
    {
        return $this->reminderCandidates()->whereNotIn('member_id', $alreadySent)->lazyById();
    }

    public function canReceiveFirstTransferReminder(int $member): bool
    {
        return $this->reminderCandidates()->where('member_id', $member)->exists();
    }

    private function reminderCandidates(): Builder
    {
        return $this->query()->whereNotNull('uid')
            ->where('statuses->kyc_status_code', '5004')
            ->where('statuses->remit_status_code', '4001')
            ->where('statuses->user_status_code', '1001')
            ->whereIn('member_id', fn ($users) => $users->from('users')->select('id')->where('is_platform_staff', false))
            ->whereNotIn('member_id', fn ($requests) => $requests->from('member_closure_requests')
                ->select('member_id')->whereIn('status', ['pending', 'approved']));
    }

    public function getOrCreate(int $member): YabandRemitMember
    {
        return $this->query()->firstOrCreate(['member_id' => $member], ['create_request_id' => (string) Str::uuid()]);
    }

    public function saveState(YabandRemitMember $link, ?string $uid, ?array $statuses): void
    {
        $link->forceFill(['uid' => $uid, 'statuses' => $statuses])->saveOrFail();
    }
}
