<?php

namespace App\Model\Notification;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $plan_id
 * @property string $plan_name
 * @property int $member_id
 * @property int $notice_id
 * @property string $push_status
 * @property Carbon $created_at
 */
#[Fillable(['plan_id', 'plan_name', 'member_id', 'notice_id'])]
final class PushReminderSend extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'push_reminder_sends';
}
