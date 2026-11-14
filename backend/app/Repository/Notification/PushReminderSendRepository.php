<?php

namespace App\Repository\Notification;

use App\Model\Notification\PushReminderPlan;
use App\Model\Notification\PushReminderSend;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** @extends AbstractRepository<PushReminderSend> */
final class PushReminderSendRepository extends AbstractRepository
{
    protected function model(): PushReminderSend
    {
        return new PushReminderSend;
    }

    public function page(int $page, ?int $plan): LengthAwarePaginator
    {
        return $this->query()
            ->join('member_notifications as notices', 'notices.id', '=', 'push_reminder_sends.notice_id')
            ->select('push_reminder_sends.*', 'notices.title', 'notices.body')
            ->when($plan !== null, fn ($query) => $query->where('plan_id', $plan))
            ->orderByDesc('push_reminder_sends.id')->paginate(20, page: $page);
    }

    public function memberIds(int $plan): Builder
    {
        return $this->query()->select('member_id')->where('plan_id', $plan);
    }

    public function create(PushReminderPlan $plan, int $member, int $notice): void
    {
        $this->query()->create(['plan_id' => $plan->id, 'plan_name' => $plan->name, 'member_id' => $member, 'notice_id' => $notice]);
    }

    public function forNotice(int $notice): ?PushReminderSend
    {
        return $this->query()->where('notice_id', $notice)->first();
    }

    public function setPushStatus(PushReminderSend $send, string $status): void
    {
        $send->forceFill(['push_status' => $status])->saveOrFail();
    }
}
