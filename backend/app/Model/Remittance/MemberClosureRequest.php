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
 * @property Carbon $created_at
 */
#[Fillable(['member_id', 'status', 'reason'])]
final class MemberClosureRequest extends Model
{
    protected $table = 'member_closure_requests';

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
