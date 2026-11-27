<?php

namespace App\Service\Remittance\Contract;

use App\Model\System\User;

interface RemittanceCheckoutTerms
{
    /** 返回当前会员已核验的渠道账户和安全展示标签。 */
    public function accounts(User $member, string $payoutMethod): array;

    /** 以有效报价与本人账户准备支付，按渠道实际能力声明是否允许重试。 */
    public function prepare(User $member, array $snapshot, string $payoutAccount, string $ip): array;
}
