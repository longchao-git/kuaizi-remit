<?php

namespace App\Model\Notification;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $title
 * @property string $body
 * @property string $send_time
 * @property string $timezone
 * @property string $status
 * @property Carbon|null $active_from
 */
#[Fillable(['name', 'title', 'body', 'send_time', 'timezone', 'status', 'active_from'])]
final class PushReminderPlan extends Model
{
    protected $table = 'push_reminder_plans';

    protected function casts(): array
    {
        return ['active_from' => 'datetime'];
    }
}
