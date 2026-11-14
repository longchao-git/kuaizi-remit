<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $settlement_date
 * @property string $filename
 * @property string $digest
 * @property int|null $imported_by
 * @property Carbon $created_at
 */
#[Fillable(['settlement_date', 'filename', 'digest', 'imported_by'])]
final class ReconciliationBatch extends Model
{
    protected $table = 'remittance_reconciliation_batches';

    protected function casts(): array
    {
        return ['settlement_date' => 'immutable_date'];
    }
}
