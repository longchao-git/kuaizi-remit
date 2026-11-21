<?php

namespace App\Service\Remittance\Yaband;

use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final class YabandRemitClient
{
    public function __construct(private YabandTransport $transport) {}

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

        return (string) $uid;
    }

    public function startIdv(string $requestId, string $uid, string $redirectUrl, string $notifyUrl): string
    {
        $data = $this->send('/api/kyc/idv', [
            ...$this->kycUser($requestId, $uid), 'redirect_url' => $redirectUrl, 'notify_url' => $notifyUrl,
        ]);
        if (is_array($data['user'] ?? null) && array_key_exists('uid', $data['user'])) {
            $responseUid = $data['user']['uid'];
            if ((! is_string($responseUid) && ! is_int($responseUid)) || (string) $responseUid !== $uid) {
                throw new YabandException('雅本身份验证响应的用户编号不匹配。');
            }
        }
        $url = $data['url'] ?? null;
        if (! is_string($url) || ! filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
            || parse_url($url, PHP_URL_USER) !== null) {
            throw new YabandException('雅本未返回有效的身份验证页面。');
        }

        return $url;
    }

    public function queryKyc(string $requestId, string $uid): array
    {
        $data = $this->send('/api/kyc/query', $this->kycUser($requestId, $uid));
        if ((string) ($data['uid'] ?? '') !== $uid) {
            throw new YabandException('雅本 KYC 响应的用户编号不匹配。');
        }

        return YabandKycStatus::fromQuery($data);
    }

    public function submitProfile(string $requestId, string $uid, string $notifyUrl, array $profile): array
    {
        return $this->send('/api/kyc/profile', [
            'type' => 'kyc', 'request_id' => $requestId, 'notify_url' => $notifyUrl,
            'user' => [...$profile, 'uid' => $uid],
        ]);
    }

    public function submitMaterials(string $requestId, string $questionId, string $reply, array $documents = []): array
    {
        $data = ['request_id' => $requestId, 'qno' => $questionId, 'reply' => $reply];
        if ($documents !== []) {
            $data['document'] = $documents;
        }

        return $this->send('/api/materials/update', $data);
    }

    public function closeAccount(string $requestId, string $uid): array
    {
        return $this->send('/api/kyc/close_account', $this->kycUser($requestId, $uid));
    }

    public function submitPayment(array $data, bool $whiteLabel): array
    {
        return $this->send($this->transactionPath('payment', $whiteLabel), [...$data, 'type' => 'transaction', 'pay_method' => 'online']);
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

    public function updateInstitutionTransfer(array $data): array
    {
        return $this->send('/api/transaction/update', [...$data, 'type' => 'transaction']);
    }

    private function kycUser(string $requestId, string $uid): array
    {
        return ['type' => 'kyc', 'request_id' => $requestId, 'user' => ['uid' => $uid]];
    }

    private function transactionPath(string $action, bool $whiteLabel): string
    {
        return ($whiteLabel ? '/api/whitelable/transaction/' : '/api/transaction/').$action;
    }

    private function send(string $path, array $data): array
    {
        if (! app()->bound(YabandRemitSigner::class)) {
            throw new YabandException('雅本汇款项目签名尚未配置。');
        }
        $body = ['mid' => $this->transport->merchantId(), 'time' => now()->timestamp, 'data' => $data];
        $sign = app(YabandRemitSigner::class)->sign($path, $body);
        if ($sign === '') {
            throw new YabandException('雅本汇款请求签名为空。');
        }

        $response = $this->transport->post($path, [...$body, 'sign' => $sign], $this->transport->token());
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
}
