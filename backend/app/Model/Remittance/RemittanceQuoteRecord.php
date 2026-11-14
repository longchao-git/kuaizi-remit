<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $reference
 * @property int $member_id
 * @property array $snapshot
 * @property Carbon $created_at
 * @property Carbon|null $draft_saved_at
 */
#[Fillable(['reference', 'member_id', 'revision_id', 'snapshot'])]
final class RemittanceQuoteRecord extends Model
{
    protected $table = 'remittance_quote_records';

    protected $casts = ['snapshot' => 'array', 'draft_saved_at' => 'datetime'];
}
