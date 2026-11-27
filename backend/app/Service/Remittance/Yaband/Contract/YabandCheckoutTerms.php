<?php

namespace App\Service\Remittance\Yaband\Contract;

use App\Model\System\User;
use App\Service\Remittance\Contract\RemittanceCheckoutTerms;

interface YabandCheckoutTerms extends RemittanceCheckoutTerms
{
    /** 返回会员本人且已核验的收款账户，label 仅包含脱敏信息。 */
    public function accounts(User $member, string $payoutMethod): array;

    /**
     * 校验锁汇与开通渠道，按已核验的本人收款账户和支付上下文组装雅本请求。
     * 金额及汇率以快照为准。返回值不得包含需要持久化的卡数据。
     * snapshot.declaration 是用户确认的用途与来源，适配器须原样映射至 payee 对应字段。
     *
     * replay_safe 仅在雅本已确认同一请求编号可安全重放的渠道启用。
     *
     * @return array{white_label: bool, fields: array, replay_safe?: bool}
     */
    public function prepare(User $member, array $snapshot, string $payoutAccount, string $ip): array;
}
