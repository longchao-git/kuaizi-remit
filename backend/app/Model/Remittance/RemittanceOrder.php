<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $reference
 * @property int $member_id
 * @property string|null $provider_trade_id
 * @property string|null $channel_scope
 * @property string|null $quote_reference
 * @property array|null $quote_snapshot
 * @property array|null $declaration
 * @property string|null $payment_request_id
 * @property string|null $payment_request_hash
 * @property string|null $payment_url
 * @property bool $white_label
 * @property int $principal_minor
 * @property string $income_code
 * @property string $outgoing_code
 * @property string $refund_code
 * @property Carbon|null $provider_checked_at
 * @property Carbon|null $provider_sync_attempted_at
 * @property Carbon $created_at
 */
#[Fillable(['quote_reference', 'reference', 'member_id', 'white_label', 'quote_snapshot', 'principal_minor', 'payment_request_id',
    'payment_request_hash', 'income_code', 'outgoing_code', 'refund_code', 'channel_scope'])]
final class RemittanceOrder extends Model
{
    protected $table = 'remittance_orders';

    protected function casts(): array
    {
        return [
            'white_label' => 'boolean',
            'principal_minor' => 'integer',
            'provider_checked_at' => 'datetime',
            'provider_sync_attempted_at' => 'datetime',
            'quote_snapshot' => 'array',
            'payment_url' => 'encrypted',
            'declaration' => 'encrypted:array',
        ];
    }
}
