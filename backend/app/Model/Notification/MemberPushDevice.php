<?php

namespace App\Model\Notification;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $token_id
 * @property int $member_id
 * @property string $client_hash
 * @property string $client_id
 */
#[Fillable(['client_hash', 'client_id', 'token_id', 'member_id'])]
#[Hidden(['client_id', 'client_hash'])]
final class MemberPushDevice extends Model
{
    protected $table = 'member_push_devices';

    protected $casts = ['client_id' => 'encrypted', 'member_id' => 'integer', 'token_id' => 'integer'];
}
