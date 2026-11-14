<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 保存规则时写入的审计快照。
 *
 * @property int $id
 * @property int|null $staff_id
 * @property string $staff_name
 * @property array $settings
 * @property Carbon $created_at
 */
#[Fillable(['staff_id', 'staff_name', 'settings'])]
final class RemittanceRuleRevision extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'remittance_rule_revisions';

    protected $casts = ['settings' => 'array'];
}
