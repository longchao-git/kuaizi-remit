<?php

namespace App\Service\Remittance\Contract;

interface RemittanceChannel
{
    /** 创建上游身份，返回后续办理所用的会员编号。 */
    public function createMember(string $requestId, string $notifyUrl, array $user): string;

    /** 发起身份验证并返回浏览器入口。 */
    public function startIdv(string $requestId, string $uid, string $redirectUrl, string $notifyUrl): string;

    /** 查询经过适配的身份状态。 */
    public function queryKyc(string $requestId, string $uid): array;

    /** 提交资料，返回带会员归属的状态字段。 */
    public function submitProfile(string $requestId, string $uid, string $notifyUrl, array $profile): array;

    /** 返回匹配问题编号的补件状态。 */
    public function submitMaterials(string $requestId, string $questionId, string $reply, array $documents = []): array;

    /** 关闭指定上游账户，响应不明确时抛出异常。 */
    public function closeAccount(string $requestId, string $uid): array;

    /** 准备付款业务字段，必须在订单及优惠券预留之前校验。 */
    public function preparePayment(array $data, bool $whiteLabel): array;

    /** 按已确认的订单和请求编号提交付款。 */
    public function submitPayment(array $data, bool $whiteLabel): array;

    /** 返回归属于既有订单的资金状态。 */
    public function queryTransaction(string $requestId, string $tradeId, bool $whiteLabel): array;
}
