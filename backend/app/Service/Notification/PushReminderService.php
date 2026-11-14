<?php

namespace App\Service\Notification;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Notification\PushReminderPlan;
use App\Model\Notification\PushReminderSend;
use App\Model\Remittance\YabandRemitMember;
use App\Repository\Notification\PushReminderDeliveryRepository;
use App\Repository\Notification\PushReminderPlanRepository;
use App\Repository\Notification\PushReminderSendRepository;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

#[Singleton]
final class PushReminderService
{
    public function __construct(
        private PushReminderPlanRepository $plans,
        private PushReminderSendRepository $sends,
        private PushReminderDeliveryRepository $deliveries,
        private YabandRemitMemberRepository $audience,
        private RemittanceCouponRepository $members,
        private RemittanceOrderRepository $orders,
        private MemberNotificationService $notices,
        private DatabaseManager $database,
    ) {}

    public function plans(int $page): Result
    {
        $rows = $this->plans->page($page);

        return $this->page($rows, array_map($this->formatPlan(...), $rows->items()));
    }

    public function sends(int $page, ?int $plan): Result
    {
        $rows = $this->sends->page($page, $plan);
        $counts = $this->deliveries->counts(array_column($rows->items(), 'id'));
        $items = array_map(static fn (PushReminderSend $row) => [
            'id' => (string) $row->id,
            'plan_id' => (string) $row->plan_id,
            'plan_name' => $row->plan_name,
            'member_id' => (string) $row->member_id,
            'notice_id' => (string) $row->notice_id,
            'title' => $row->getAttribute('title'),
            'body' => $row->getAttribute('body'),
            'push_status' => $row->push_status,
            'deliveries' => (object) ($counts[$row->id] ?? []),
            'created_at' => $row->created_at->toISOString(),
        ], $rows->items());

        return $this->page($rows, $items);
    }

    public function save(?int $id, array $input): Result
    {
        if ($id === null) {
            return Result::success($this->formatPlan($this->plans->create($input)), '提醒计划草稿已保存');
        }

        return $this->change($id, function (PushReminderPlan $plan) use ($input): Result {
            if ($plan->status === 'active') {
                return Result::fail('请先暂停计划再编辑', 409);
            }
            $this->plans->update($plan, $input);

            return Result::success($this->formatPlan($plan), '提醒计划已保存');
        });
    }

    public function setActive(int $id, bool $active): Result
    {
        return $this->change($id, function (PushReminderPlan $plan) use ($active): Result {
            if ($active !== ($plan->status === 'active')) {
                $this->plans->update($plan, ['status' => $active ? 'active' : 'paused', 'active_from' => $active ? now() : null]);
            }

            return Result::success($this->formatPlan($plan), $active ? '提醒计划已启用' : '提醒计划已暂停');
        });
    }

    /** Enabling a plan schedules the next local slot. Each member receives it once per plan. */
    public function runDue(): int
    {
        $instant = now();
        $sent = 0;
        foreach ($this->plans->active() as $plan) {
            if (! $this->due($plan, $instant)) {
                continue;
            }
            foreach ($this->audience->eligibleForFirstTransferReminder($this->sends->memberIds($plan->id)) as $link) {
                $sent += (int) $this->sendIfEligible($plan->id, $link, $instant);
            }
        }

        return $sent;
    }

    private function due(PushReminderPlan $plan, Carbon $instant): bool
    {
        if ($plan->status !== 'active' || $plan->active_from === null) {
            return false;
        }
        $local = $instant->copy()->timezone($plan->timezone);
        $slot = $local->copy()->setTimeFromTimeString($plan->send_time);
        if ($local->lessThan($slot)) {
            $slot = $local->copy()->subDay()->setTimeFromTimeString($plan->send_time);
        }

        return $slot->greaterThanOrEqualTo($plan->active_from)
            && $instant->lessThan($slot->copy()->utc()->addHour());
    }

    private function sendIfEligible(int $planId, YabandRemitMember $link, Carbon $instant): bool
    {
        $member = $link->member_id;

        return $this->database->transaction(function () use ($planId, $member, $instant): bool {
            $plan = $this->plans->lock($planId);
            if ($plan === null || ! $this->due($plan, $instant)
                || $this->sends->memberIds($planId)->where('member_id', $member)->exists()) {
                return false;
            }
            $this->members->lockMember($member);
            if (! $this->audience->canReceiveFirstTransferReminder($member) || $this->orders->hasPaidOrPending($member)) {
                return false;
            }
            $result = $this->notices->send($member, null, [
                'request_id' => (string) Str::uuid(), 'title' => $plan->title, 'body' => $plan->body,
            ], broadcast: false);
            if ($result->isFail()) {
                throw new RuntimeException('无法保存计划站内提醒');
            }

            $this->sends->create($plan, $member, $result->getData()['id']);
            $this->database->afterCommit(fn () => $this->notices->refreshInbox($member));

            return true;
        }, attempts: 3);
    }

    private function change(int $id, callable $change): Result
    {
        return $this->database->transaction(function () use ($id, $change): Result {
            $plan = $this->plans->lock($id);

            return $plan === null ? Result::fail('提醒计划不存在', 404) : $change($plan);
        });
    }

    private function formatPlan(PushReminderPlan $plan): array
    {
        return [
            'id' => (string) $plan->id, 'name' => $plan->name, 'title' => $plan->title, 'body' => $plan->body,
            'send_time' => $plan->send_time, 'timezone' => $plan->timezone, 'status' => $plan->status,
        ];
    }

    private function page(LengthAwarePaginator $rows, array $items): Result
    {
        return Result::success(PaginationPayload::from($items, $rows->total(), $rows->currentPage(), $rows->perPage())->toArray());
    }
}
