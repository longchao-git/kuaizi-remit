<?php

namespace App\Service\Remittance;

use App\Model\Remittance\YabandRemitMember;
use App\Model\System\User;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Contract\RemittanceChannel;
use App\Service\Remittance\Contract\RemittanceCheckoutTerms;
use App\Service\Remittance\Contract\RemittanceRateLock;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandKycStatus;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/** 使用本地记录明确模拟上游状态，整个适配器没有网络依赖。 */
final readonly class LocalRemittanceChannel implements RemittanceChannel, RemittanceCheckoutTerms, RemittanceRateLock
{
    public function __construct(
        private RemittanceOrderRepository $orders,
        private YabandRemitMemberRepository $members,
    ) {}

    public function lock(int $member, array $request): array
    {
        $rate = filter_var(config('remit.local_rate_ten_thousandths'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1_000_000],
        ]);
        if ($rate === false) {
            throw new YabandException('请设置本地模拟汇率。');
        }

        return ['reference' => 'sim-'.Str::uuid(), 'expires_at' => now()->addMinutes(10)->timestamp, 'rate_ten_thousandths' => $rate];
    }

    public function createMember(string $requestId, string $notifyUrl, array $user): string
    {
        return 'sim-'.$requestId;
    }

    public function startIdv(string $requestId, string $uid, string $redirectUrl, string $notifyUrl): string
    {
        $link = $this->link($uid);
        $this->members->saveState($link, $uid, YabandKycStatus::fromQuery([
            'user_status_code' => '1001', 'remit_status_code' => '4002', 'kyc_status_code' => '5002',
            'idv_status_code' => '2006', 'profile_status_code' => '3001',
        ]));

        return '';
    }

    public function queryKyc(string $requestId, string $uid): array
    {
        return $this->link($uid)->statuses ?? YabandKycStatus::fromQuery([
            'user_status_code' => '1001', 'remit_status_code' => '4002', 'kyc_status_code' => '5001',
            'idv_status_code' => '2001', 'profile_status_code' => '3001',
        ]);
    }

    public function submitProfile(string $requestId, string $uid, string $notifyUrl, array $profile): array
    {
        $this->link($uid);

        return ['uid' => $uid, 'user_status_code' => '1001', 'remit_status_code' => '4001', 'kyc_status_code' => '5004',
            'idv_status_code' => '2006', 'profile_status_code' => '3002'];
    }

    public function submitMaterials(string $requestId, string $questionId, string $reply, array $documents = []): array
    {
        return ['qno' => $questionId, 'qno_status_code' => '6002'];
    }

    public function closeAccount(string $requestId, string $uid): array
    {
        $link = $this->link($uid);
        $this->members->saveState($link, $uid, YabandKycStatus::fromQuery([
            ...($link->statuses ?? []), 'user_status_code' => '1002', 'remit_status_code' => '4002',
        ]));

        return ['uid' => $uid, 'status' => 'inactive'];
    }

    public function accounts(User $member, string $payoutMethod): array
    {
        $link = $this->members->findFor((int) $member->id);
        if ($link?->channel_scope !== RemittanceChannels::LOCAL || ! ($link->statuses['kyc_passed'] ?? false)
            || ! ($link->statuses['remittance_enabled'] ?? false)) {
            return [];
        }

        return [[
            'reference' => (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'kuaizi-remit:local:'.$link->uid.':'.$payoutMethod),
            'label' => $payoutMethod === 'wechat' ? '模拟本人微信 · 0000' : '模拟本人银行卡 · 0000',
        ]];
    }

    public function prepare(User $member, array $snapshot, string $payoutAccount, string $ip): array
    {
        $account = $this->accounts($member, $snapshot['payout_method'])[0]['reference'] ?? null;
        if ($account === null || $payoutAccount !== $account) {
            throw new YabandException('请选择当前会员的模拟收款账户。');
        }
        $declaration = $snapshot['declaration'];

        return ['white_label' => true, 'replay_safe' => true, 'fields' => ['payee' => [
            'relationship' => '01', 'source_of_funds' => $declaration['source_of_funds'],
            'purpose_and_explanation_of_the_transfer' => $declaration['purpose'], 'explanation' => $declaration['explanation'],
        ]]];
    }

    public function preparePayment(array $data, bool $whiteLabel): array
    {
        if (! $whiteLabel || ! str_starts_with($data['uid'] ?? '', 'sim-')) {
            throw new YabandException('模拟支付仅接受本地身份。');
        }

        return $data;
    }

    public function submitPayment(array $data, bool $whiteLabel): array
    {
        $this->preparePayment($data, $whiteLabel);

        return ['trade_id' => 'sim-'.$data['order_id'], ...array_intersect_key($data, array_flip(['uid', 'amount', 'currency', 'order_id']))];
    }

    public function queryTransaction(string $requestId, string $tradeId, bool $whiteLabel): array
    {
        $order = str_starts_with($tradeId, 'sim-') ? $this->orders->find(substr($tradeId, 4), null) : null;
        if ($order?->channel_scope !== RemittanceChannels::LOCAL || $order->provider_trade_id !== $tradeId) {
            throw new YabandException('找不到模拟订单。');
        }
        [$income, $outgoing, $refund] = match (config('remit.local_scenario')) {
            'pending' => ['03', '01', '00'],
            'paid' => ['00', '01', '00'],
            'delivered' => ['00', '05', '00'],
            'failed' => ['06', '01', '00'],
            'refunding' => ['00', '03', '02'],
            'refunded' => ['00', '03', '04'],
            default => throw new YabandException('模拟交易状态配置无效。'),
        };

        return ['transaction_info' => [
            'order_id' => $order->reference, 'trade_id' => $tradeId, 'currency' => 'EUR',
            'amount' => sprintf('%d.%02d', intdiv($order->principal_minor, 100), $order->principal_minor % 100),
            'income_status_code' => $income, 'outgoing_status_code' => $outgoing, 'refund_status_code' => $refund,
        ]];
    }

    private function link(string $uid): YabandRemitMember
    {
        $link = $this->members->findByUid($uid);
        if ($link?->channel_scope !== RemittanceChannels::LOCAL) {
            throw new YabandException('找不到模拟身份。');
        }

        return $link;
    }
}
