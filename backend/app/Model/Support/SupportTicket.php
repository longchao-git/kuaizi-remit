<?php

namespace App\Model\Support;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property string $subject
 * @property string $body
 * @property string $status
 * @property string|null $order_reference
 * @property int $version
 * @property Carbon $created_at
 */
#[Fillable(['member_id', 'client_id', 'subject', 'body', 'status', 'order_reference'])]
final class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $casts = ['version' => 'integer', 'member_id' => 'integer'];
}
