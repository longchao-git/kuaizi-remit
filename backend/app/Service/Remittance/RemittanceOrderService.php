<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\RemittanceOrder;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Notification\MemberNotificationService;
use App\Service\Remittance\Yaband\YabandException;
use App\Service\Remittance\Yaband\YabandRemitClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

#[Singleton]
final class RemittanceOrderService
{
    public function __construct(
        private RemittanceOrderRepository $orders,
        private YabandRemitClient $yaband,
        private MemberNotificationService $notices,
        private DatabaseManager $database,
        private RemittanceCouponRepository $coupons,
        private YabandRemitMemberRepository $members,
    ) {}

    /** 返回保存的订单。App 始终带当前会员编号作为归属条件。 */
    public function page(int $page, ?int $member, ?string $reference = null, bool $staff = false, array $filters = []): Result
    {
        $rows = $this->orders->page($page, $member, $reference, $filters);
        $items = array_map(fn (RemittanceOrder $order) => $this->format($order, $staff), $rows->items());
        $payload = PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray();
        if ($staff) {
            $payload['summary'] = $this->orders->staffTotals($member, $reference, $filters);
        }

        return Result::success($payload);
    }

    public function details(string $reference, int $member): Result
    {
        $order = $this->orders->find($reference, $member);

        return $order === null ? Result::fail('订单不存在', 404) : Result::success($this->format($order, paymentDetails: true));
    }

    /** 员工按订单查看持久化的快照，不返回用户付款链接。 */
    public function staffDetails(string $reference): Result
    {
        $order = $this->orders->find(strtolower($reference), null);

        return $order === null ? Result::fail('订单不存在', 404) : Result::success($this->format($order, staff: true));
    }

    /** 先按本地绑定核对通知标识，资金状态以主动查询结果为准。 */
    public function refreshNotified(string $reference, string $trade): Result
    {
        if (! Str::isUuid($reference) || $trade === '' || strlen($trade) > 64) {
            return Result::fail('交易标识无效', 422);
        }
        $order = $this->orders->find(strtolower($reference), null);
        if ($order === null || $order->provider_trade_id !== $trade) {
            return Result::fail('交易尚未关联本地订单', 404);
        }

        return $this->refresh($order->reference);
    }

    /** 查询已有雅本交易，核对订单、交易及返回的会员归属后保存三条独立状态。 */
    public function refresh(string $reference): Result
    {
        $reference = strtolower($reference);
        $lock = Cache::lock('remittance-order:'.$reference, 40);
        if (! $lock->get()) {
            return Result::fail('订单正在更新，请稍后重试', 409);
        }

        try {
            $order = $this->orders->find($reference, null);
            if ($order === null) {
                return Result::fail('订单不存在', 404);
            }
            if ($order->provider_trade_id === null) {
                return Result::fail('订单尚未取得雅本交易编号', 409);
            }

            $reply = $this->yaband->queryTransaction((string) Str::uuid(), $order->provider_trade_id, $order->white_label);
            $transaction = $reply['transaction_info'] ?? null;
            if (! is_array($transaction) || ($transaction['order_id'] ?? null) !== $order->reference
                || ($transaction['trade_id'] ?? null) !== $order->provider_trade_id) {
                return Result::fail('雅本返回的交易与订单不匹配', 502);
            }

            if (array_key_exists('uid', $transaction)) {
                $providerUid = $transaction['uid'];
                $memberUid = $this->members->findFor((int) $order->member_id)?->uid;
                if ((! is_string($providerUid) && ! is_int($providerUid)) || $memberUid === null
                    || (string) $providerUid !== $memberUid) {
                    return Result::fail('雅本返回的用户与订单不匹配', 502);
                }
            }

            $income = $transaction['income_status_code'] ?? null;
            $outgoing = $transaction['outgoing_status_code'] ?? null;
            $refund = $transaction['refund_status_code'] ?? null;
            foreach ([$income, $outgoing, $refund] as $code) {
                if (! is_string($code) || ! preg_match('/^[0-9]{2}$/D', $code)) {
                    return Result::fail('雅本返回的订单状态不完整', 502);
                }
            }
            $this->saveState($order, $income, $outgoing, $refund);

            return Result::success($this->format($order, true));
        } catch (YabandException|LockTimeoutException) {
            return Result::fail('暂无法查询雅本交易，请稍后重试', 503);
        } finally {
            $lock->release();
        }
    }

    private function saveState(RemittanceOrder $order, string $income, string $outgoing, string $refund): void
    {
        $title = match (true) {
            $refund === '04' && $order->refund_code !== '04' => '汇款订单已退款',
            $outgoing === '05' && $order->outgoing_code !== '05' && $income === '00' && $refund === '00' => '汇款已下发',
            $income === '00' && $order->income_code !== '00' && $refund === '00' => '已确认收到汇款付款',
            default => null,
        };
        $this->database->transaction(function () use ($order, $income, $outgoing, $refund, $title): void {
            $this->coupons->lockMember((int) $order->member_id);
            $this->orders->saveProviderState($order, $income, $outgoing, $refund);
            $this->coupons->syncOrder($order->reference, $income, $refund);
            if ($title !== null) {
                $this->notices->send((int) $order->member_id, null, [
                    'request_id' => (string) Str::uuid(), 'title' => $title,
                    'body' => '请在汇款订单查看详情。订单编号 '.$order->reference,
                    'order_reference' => $order->reference,
                ], broadcast: false);
            }
        });
        if ($title !== null) {
            $this->notices->refreshInbox((int) $order->member_id);
        }
    }

    private function format(RemittanceOrder $order, bool $staff = false, bool $paymentDetails = false): array
    {
        $result = [
            'reference' => $order->reference,
            'principal_minor' => (int) $order->principal_minor,
            'income_code' => $order->income_code,
            'outgoing_code' => $order->outgoing_code,
            'refund_code' => $order->refund_code,
            'status_label' => $this->statusLabel($order),
            'provider_checked_at' => $order->provider_checked_at?->toISOString(),
            'created_at' => $order->created_at->toISOString(),
        ];
        if ($order->quote_snapshot !== null) {
            $result['quote'] = array_intersect_key($order->quote_snapshot, array_flip([
                'payout_minor', 'rate_ten_thousandths', 'base_fee_minor', 'discount_minor',
                'channel_fee_minor', 'total_payable_minor', 'eta',
            ]));
            if ($paymentDetails) {
                $result['payment_url'] = in_array($order->income_code, ['02', '03'], true)
                    && $order->refund_code === '00' && ($order->quote_snapshot['expires_at'] ?? 0) > now()->timestamp
                    ? $order->payment_url : null;
            }
        }

        return $staff ? [
            ...$result,
            'member_id' => (string) $order->member_id,
            'trade_id' => $order->provider_trade_id,
            'payment_method' => $order->quote_snapshot['payment_method'] ?? null,
            'payout_method' => $order->quote_snapshot['payout_method'] ?? null,
        ] : $result;
    }

    public function statusLabel(RemittanceOrder $order): string
    {
        return match (true) {
            $order->refund_code === '04' => '已退款',
            in_array($order->refund_code, ['01', '02', '03'], true) => '退款处理中',
            in_array($order->refund_code, ['05', '06'], true) => '退款异常',
            $order->outgoing_code === '05' && $order->income_code === '00' => '已下发',
            in_array($order->outgoing_code, ['03', '06', '07'], true) && $order->income_code === '00' => '下发异常',
            $order->income_code === '00' => '已付款，汇款处理中',
            $order->income_code === '04' => '付款审核中',
            in_array($order->income_code, ['05', '06', '07', '08'], true) => '付款未完成',
            in_array($order->income_code, ['01', '02', '03'], true) => '待确认付款',
            default => '等待核对状态',
        };
    }
}
