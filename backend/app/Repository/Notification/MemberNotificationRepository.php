<?php

namespace App\Repository\Notification;

use App\Model\Notification\MemberNotification;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/** @extends AbstractRepository<MemberNotification> */
final class MemberNotificationRepository extends AbstractRepository
{
    protected function model(): MemberNotification
    {
        return new MemberNotification;
    }

    public function page(int $member, int $page): LengthAwarePaginator
    {
        return $this->query()->where('member_id', $member)->orderByDesc('id')->paginate(20, page: $page);
    }

    public function unreadCount(int $member): int
    {
        return $this->query()->where('member_id', $member)->whereNull('read_at')->count();
    }

    public function findOwned(int $member, int $notice): ?MemberNotification
    {
        return $this->query()->where('member_id', $member)->find($notice);
    }

    public function markRead(int $member, int $notice, bool $through): int
    {
        return $this->query()->where('member_id', $member)->whereNull('read_at')
            ->where('id', $through ? '<=' : '=', $notice)
            ->update(['read_at' => now()]);
    }

    public function createOnce(string $requestId, array $attributes): MemberNotification
    {
        return $this->query()->createOrFirst(['request_id' => $requestId], $attributes);
    }
}
