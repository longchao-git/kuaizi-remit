<?php

namespace App\Model\Notification;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $send_id
 * @property int $device_id
 * @property int $token_id
 * @property string $status
 */
#[Fillable(['send_id', 'device_id', 'token_id', 'status'])]
final class PushReminderDelivery extends Model
{
    protected $table = 'push_reminder_deliveries';
}
