<?php

namespace App\Repository\Notification;

use App\Model\Notification\PushReminderDelivery;
use App\Repository\AbstractRepository;

/** @extends AbstractRepository<PushReminderDelivery> */
final class PushReminderDeliveryRepository extends AbstractRepository
{
    protected function model(): PushReminderDelivery
    {
        return new PushReminderDelivery;
    }

    public function prepare(int $send, int $device, int $token): PushReminderDelivery
    {
        return $this->query()->createOrFirst([
            'send_id' => $send, 'device_id' => $device, 'token_id' => $token,
        ], ['status' => 'queued']);
    }

    public function finish(int $id, string $status): void
    {
        $this->query()->whereKey($id)->where('status', 'queued')->update(['status' => $status]);
    }

    public function awaiting(int $id): bool
    {
        return $this->query()->whereKey($id)->where('status', 'queued')->exists();
    }

    public function counts(array $sendIds): array
    {
        $counts = [];
        foreach ($this->query()->whereIn('send_id', $sendIds)->selectRaw('send_id, status, COUNT(*) AS device_count')
            ->groupBy('send_id', 'status')->get() as $row) {
            $counts[$row->send_id][$row->status] = (int) $row->getAttribute('device_count');
        }

        return $counts;
    }
}
