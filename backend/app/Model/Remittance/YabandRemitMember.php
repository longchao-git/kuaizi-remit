<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $member_id
 * @property string $create_request_id
 * @property string|null $uid
 * @property array|null $statuses
 */
#[Fillable(['member_id', 'create_request_id'])]
final class YabandRemitMember extends Model
{
    protected $table = 'yaband_remit_members';

    protected $casts = ['statuses' => 'array'];
}
