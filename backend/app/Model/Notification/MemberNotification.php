<?php

namespace App\Model\Notification;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 会员站内通知。
 *
 * @property int $id
 * @property int $member_id
 * @property int|null $sender_id
 * @property int|null $support_ticket_id
 * @property string|null $order_reference
 * @property string $request_id
 * @property string $title
 * @property string $body
 * @property Carbon|null $read_at
 * @property Carbon $created_at
 */
#[Fillable(['member_id', 'sender_id', 'request_id', 'title', 'body', 'support_ticket_id', 'order_reference'])]
final class MemberNotification extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'member_notifications';

    protected function casts(): array
    {
        return ['member_id' => 'integer', 'sender_id' => 'integer', 'support_ticket_id' => 'integer', 'read_at' => 'datetime'];
    }
}
