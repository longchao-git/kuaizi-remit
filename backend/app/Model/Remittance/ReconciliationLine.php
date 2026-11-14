<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $batch_id
 * @property string $order_reference
 * @property int $imported_minor
 * @property int|null $expected_minor
 * @property string|null $imported_status
 * @property string|null $expected_status
 * @property string $result
 * @property int|null $reviewed_by
 * @property string|null $review_note
 * @property Carbon|null $reviewed_at
 */
final class ReconciliationLine extends Model
{
    public $timestamps = false;

    protected $table = 'remittance_reconciliation_lines';

    protected function casts(): array
    {
        return ['imported_minor' => 'integer', 'expected_minor' => 'integer', 'reviewed_at' => 'datetime'];
    }
}
