<?php

namespace App\Model\Remittance;

use Illuminate\Database\Eloquent\Model;

/**
 * 本地收款资料草稿，不代表本人账户核验通过。
 *
 * @property string $reference
 * @property int $member_id
 * @property string $payout_method
 * @property array $details
 * @property string $revision
 */
final class MemberPayoutAccountDraft extends Model
{
    protected $table = 'member_payout_account_drafts';

    protected $primaryKey = 'reference';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['details' => 'encrypted:array'];
    }
}
