<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * 独立优惠券或一次性优惠码。
 *
 * @property int $id
 * @property string $request_id
 * @property string $issue_fingerprint
 * @property int|null $member_id
 * @property bool $home_popup
 * @property int|null $issued_by
 * @property int|null $revoked_by
 * @property string|null $code
 * @property string $title
 * @property int $discount_bps
 * @property int|null $template_id
 * @property string|null $payout_method
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $revoked_at
 * @property string|null $order_reference
 * @property Carbon|null $redeemed_at
 * @property Carbon $created_at
 */
#[Fillable(['request_id', 'issue_fingerprint', 'member_id', 'home_popup', 'issued_by', 'code', 'title', 'discount_bps', 'expires_at', 'template_id', 'payout_method'])]
final class RemittanceCoupon extends Model
{
    public const FIRST_ORDER_BPS = 10_000;

    protected $table = 'remittance_coupons';

    protected $casts = [
        'member_id' => 'integer',
        'home_popup' => 'boolean',
        'discount_bps' => 'integer',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'revoked_at' => 'datetime',
        'redeemed_at' => 'datetime',
    ];

    public static function typeForDiscount(int $discount): string
    {
        return $discount === self::FIRST_ORDER_BPS ? 'first_order_free' : 'discount';
    }
}
