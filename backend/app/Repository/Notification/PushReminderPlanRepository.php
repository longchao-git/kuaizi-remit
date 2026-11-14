<?php

namespace App\Repository\Notification;

use App\Model\Notification\PushReminderPlan;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/** @extends AbstractRepository<PushReminderPlan> */
final class PushReminderPlanRepository extends AbstractRepository
{
    protected function model(): PushReminderPlan
    {
        return new PushReminderPlan;
    }

    public function page(int $page): LengthAwarePaginator
    {
        return $this->query()->orderByDesc('id')->paginate(20, page: $page);
    }

    public function lock(int $id): ?PushReminderPlan
    {
        return $this->query()->lockForUpdate()->find($id);
    }

    public function create(array $input): PushReminderPlan
    {
        return $this->query()->create([...$input, 'status' => 'draft']);
    }

    public function update(PushReminderPlan $plan, array $input): void
    {
        $plan->fill($input)->saveOrFail();
    }

    public function active(): LazyCollection
    {
        return $this->query()->where('status', 'active')->where('active_from', '<=', now())->lazyById();
    }
}
