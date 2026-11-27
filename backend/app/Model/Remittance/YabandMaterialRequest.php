<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\CarbonImmutable;

/**
 * @property string $reference
 * @property int $member_id
 * @property string|null $order_reference
 * @property string $question_id
 * @property string|null $channel_scope
 * @property string $instruction
 * @property CarbonImmutable $deadline_at
 * @property string $status
 * @property string|null $submission_id
 * @property string|null $submission_hash
 */
#[Fillable(['reference', 'member_id', 'order_reference', 'question_id', 'instruction', 'deadline_at', 'status', 'channel_scope'])]
final class YabandMaterialRequest extends Model
{
    protected $table = 'yaband_material_requests';

    protected $primaryKey = 'reference';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $attributes = ['status' => '6001'];

    protected $casts = ['deadline_at' => 'immutable_datetime', 'member_id' => 'integer'];
}
