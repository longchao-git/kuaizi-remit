<?php

namespace App\Model\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property int $sender_id
 * @property string $client_id
 * @property string $body
 * @property Carbon $created_at
 * @property string $member_name 仅用于会话列表。
 * @property int|null $unread_count 仅用于会话列表。
 */
final class SupportMessage extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'support_messages';

    protected $fillable = ['member_id', 'sender_id', 'client_id', 'body'];
}
