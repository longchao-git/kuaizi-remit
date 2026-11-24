<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $member_id
 * @property string $status
 * @property string|null $reason
 * @property int|null $reviewed_by
 * @property string|null $review_note
 * @property Carbon|null $reviewed_at
 * @property string $execution_status
 * @property string|null $execution_request_id
 * @property string|null $execution_uid
 * @property string|null $execution_note
 * @property Carbon|null $execution_started_at
 * @property Carbon|null $executed_at
 * @property int|null $executed_by
 * @property Carbon|null $last_checked_at
 * @property int|null $last_checked_by
 * @property Carbon $created_at
 */
#[Fillable(['member_id', 'status', 'reason'])]
final class MemberClosureRequest extends Model
{
    protected $table = 'member_closure_requests';

    protected $attributes = ['execution_status' => 'not_started'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime', 'execution_started_at' => 'datetime', 'executed_at' => 'datetime', 'last_checked_at' => 'datetime'];
    }
}
