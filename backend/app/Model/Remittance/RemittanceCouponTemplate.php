<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $title
 * @property int $discount_bps
 * @property int $valid_days
 * @property string|null $payout_method
 * @property bool $enabled
 */
#[Fillable(['title', 'discount_bps', 'valid_days', 'payout_method', 'enabled'])]
final class RemittanceCouponTemplate extends Model
{
    protected $table = 'remittance_coupon_templates';

    protected $casts = ['discount_bps' => 'integer', 'valid_days' => 'integer', 'enabled' => 'boolean'];
}
