<?php

namespace App\Service\Remittance\Yaband;

use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final class YabandRemitClient
{
    /** 复用鉴权、HTTP传输与响应解析，不在业务方法里自动重放请求。 */
    public function __construct(private YabandTransport $transport) {}

    /** 创建用户后核对外部会员编号及返回UID，再交给会员服务绑定。 */
    public function createMember(string $requestId, string $notifyUrl, array $user): string
    {
        $data = $this->send('/api/kyc/new', [
            'type' => 'kyc', 'request_id' => $requestId, 'notify_url' => $notifyUrl, 'user' => $user,
        ]);
        $uid = $data['user']['uid'] ?? null;
        if ((! is_string($uid) && ! is_int($uid)) || trim((string) $uid) === '' || strlen((string) $uid) > 64) {
            throw new YabandException('雅本开户响应缺少有效的用户编号。');
        }
        if (array_key_exists('external_uid', $data['user'])
            && $data['user']['external_uid'] !== ($user['external_uid'] ?? null)) {
            throw new YabandException('雅本开户响应的会员编号不匹配。');
        }
        $this->assertResponseUser($data, (string) $uid, '雅本开户响应的用户编号不匹配。');

        return (string) $uid;
    }

    /** 返回已核对用户归属的HTTPS身份验证地址。 */
    public function startIdv(string $requestId, string $uid, string $redirectUrl, string $notifyUrl): string
    {
        $data = $this->send('/api/kyc/idv', [
            ...$this->kycUser($requestId, $uid), 'redirect_url' => $redirectUrl, 'notify_url' => $notifyUrl,
        ]);
        $this->assertResponseUser($data, $uid, '雅本身份验证响应的用户编号不匹配。');
        $url = $data['url'] ?? null;
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null) {
            throw new YabandException('雅本未返回有效的身份验证页面。');
        }

        return $url;
    }

    /** 核对各处已返回的用户编号，只提取认证状态，不返回身份原文。 */
    public function queryKyc(string $requestId, string $uid): array
    {
        $data = $this->send('/api/kyc/query', $this->kycUser($requestId, $uid));
        $this->assertResponseUser($data, $uid, '雅本 KYC 响应的用户编号不匹配。', requireUid: true);

        return YabandKycStatus::fromQuery($data);
    }

    /** 提交资料后先核对响应归属，拒绝其他会员或格式错误的结果。 */
    public function submitProfile(string $requestId, string $uid, string $notifyUrl, array $profile): array
    {
        $data = $this->send('/api/kyc/profile', [
            'type' => 'kyc', 'request_id' => $requestId, 'notify_url' => $notifyUrl,
            'user' => [...$profile, 'uid' => $uid],
        ]);
        $this->assertResponseUser($data, $uid, '雅本资料响应的用户编号不匹配。', requireUid: true);

        return $data;
    }

    /** 按上游问题编号回复补件，保留附件的既有JSON结构。 */
    public function submitMaterials(string $requestId, string $questionId, string $reply, array $documents = []): array
    {
        $data = ['request_id' => $requestId, 'qno' => $questionId, 'reply' => $reply];
        if ($documents !== []) {
            $data['document'] = $documents;
        }

        return $this->send('/api/materials/update', $data);
    }

    /** 仅封装明确指定用户的注销请求，不代替本地业务执行门禁。 */
    public function closeAccount(string $requestId, string $uid): array
    {
        return $this->send('/api/kyc/close_account', $this->kycUser($requestId, $uid));
    }

    /** 复用已准备好的支付参数，仅固定供应商要求的业务类型与在线支付方式。 */
    public function submitPayment(array $data, bool $whiteLabel): array
    {
        return $this->send($this->transactionPath('payment', $whiteLabel), [...$data, 'type' => 'transaction', 'pay_method' => 'online']);
    }

    /** 查询 EUR→CNY 的参考汇率，不创建报价锁定或支付订单。 */
    public function queryReferenceRate(string $requestId): string
    {
        $data = $this->send('/api/whitelable/transaction/queryExchangeRate', [
            'request_id' => $requestId, 'source_currency' => 'EUR', 'target_currency' => 'CNY',
        ], preserveDecimals: true);
        $rate = $data['exchange_rate'] ?? null;
        if (($data['source_currency'] ?? null) !== 'EUR' || ($data['target_currency'] ?? null) !== 'CNY'
            || (! is_string($rate) && ! is_int($rate))) {
            throw new YabandException('雅本返回的参考汇率格式或币种不匹配。');
        }

        return (string) $rate;
    }

    /** 查询机构或白标交易，并核对交易内的商户归属后交给订单服务同步。 */
    public function queryTransaction(string $requestId, string $tradeId, bool $whiteLabel): array
    {
        $response = $this->send($this->transactionPath('query', $whiteLabel), [
            'type' => 'transaction', 'request_id' => $requestId, 'trade_id' => $tradeId,
        ]);
        if (is_array($response['transaction_info'] ?? null)) {
            $this->assertMerchant($response['transaction_info']);
        }

        return $response;
    }

    /** 封装机构模式的汇款更新，不自动发起资金下发。 */
    public function updateInstitutionTransfer(array $data): array
    {
        return $this->send('/api/transaction/update', [...$data, 'type' => 'transaction']);
    }

    /** 组装当前用户的KYC请求公共字段。 */
    private function kycUser(string $requestId, string $uid): array
    {
        return ['type' => 'kyc', 'request_id' => $requestId, 'user' => ['uid' => $uid]];
    }

    /** 按已明确的机构或白标模式选择路径，不推断商户开通能力。 */
    private function transactionPath(string $action, bool $whiteLabel): string
    {
        return ($whiteLabel ? '/api/whitelable/transaction/' : '/api/transaction/').$action;
    }

    /** 对业务请求签名并核对响应归属，参考汇率可要求保留小数文本。 */
    private function send(string $path, array $data, bool $preserveDecimals = false): array
    {
        if (! app()->bound(YabandRemitSigner::class)) {
            throw new YabandException('雅本汇款项目签名尚未配置。');
        }
        $body = ['mid' => $this->transport->merchantId(), 'time' => now()->timestamp, 'data' => $data];
        $sign = app(YabandRemitSigner::class)->sign($path, $body);
        if ($sign === '') {
            throw new YabandException('雅本汇款请求签名为空。');
        }

        $response = $this->transport->post($path, [...$body, 'sign' => $sign], $this->transport->token(), $preserveDecimals);
        $this->assertMerchant($response);
        if (isset($data['type']) && array_key_exists('type', $response) && $response['type'] !== $data['type']) {
            throw new YabandException('雅本响应的业务类型不匹配。');
        }

        return $response;
    }

    /** 响应携带商户编号时须匹配当前配置；拒绝其他商户或类型错误的编号。 */
    private function assertMerchant(array $response): void
    {
        if (! array_key_exists('mid', $response)) {
            return;
        }
        $responseMid = $response['mid'];
        if ((! is_string($responseMid) && ! is_int($responseMid)) || (string) $responseMid !== $this->transport->merchantId()) {
            throw new YabandException('雅本响应的商户编号不匹配。');
        }
    }

    /** 核对实际出现的顶层及嵌套UID，兼容供应商保留为JSON字符串的user。 */
    private function assertResponseUser(array $data, string $uid, string $message, bool $requireUid = false): void
    {
        if ($requireUid || array_key_exists('uid', $data)) {
            $this->assertUid($data['uid'] ?? null, $uid, $message);
        }
        $user = $data['user'] ?? null;
        if (is_string($user)) {
            $user = json_decode($user, true, 64, JSON_BIGINT_AS_STRING);
        }
        if (is_array($user) && array_key_exists('uid', $user)) {
            $this->assertUid($user['uid'], $uid, $message);
        }
    }

    /** 不强制转换数组、浮点或布尔值，避免PHP异常或将错误编号误认成会员。 */
    private function assertUid(mixed $value, string $expected, string $message): void
    {
        if ((! is_string($value) && ! is_int($value)) || (string) $value !== $expected) {
            throw new YabandException($message);
        }
    }
}
