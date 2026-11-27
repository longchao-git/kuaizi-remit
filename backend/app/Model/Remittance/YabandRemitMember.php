<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $member_id
 * @property string $create_request_id
 * @property string|null $uid
 * @property string|null $channel_scope
 * @property string|null $external_uid 创建身份时固定的外部编号，历史绑定允许为空
 * @property array|null $statuses
 */
#[Fillable(['member_id', 'create_request_id', 'channel_scope'])]
final class YabandRemitMember extends Model
{
    protected $table = 'yaband_remit_members';

    protected $casts = ['statuses' => 'array'];
}
