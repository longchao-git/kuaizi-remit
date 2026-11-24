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
use DateTimeInterface;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

#[Singleton]
final class RemittanceOrderService
{
    /** 复用本地订单、供应商查询及通知，资金状态仅在核对后保存。 */
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

    /** 会员只能读取自己的订单和当前可用付款信息。 */
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
        return $this->refreshOrder($reference);
    }

    /** 补偿任务仅查询到期的未结清订单；锁内发现已更新或结清时返回 null。 */
    public function refreshIfDue(string $reference, DateTimeInterface $before): ?Result
    {
        return $this->refreshOrder($reference, $before);
    }

    /** 复用单订单锁，先记录尝试，再校验上游结果并同步资金状态。 */
    private function refreshOrder(string $reference, ?DateTimeInterface $before = null): ?Result
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
            if ($before !== null && ! $this->orders->isSyncDue($order, $before)) {
                return null;
            }
            if ($order->provider_trade_id === null || $order->provider_trade_id === '') {
                return Result::fail('订单尚未取得雅本交易编号', 409);
            }

            $this->orders->markSyncAttempt($order);
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
            if (! $this->matchesPaymentTerms($transaction, $order)) {
                return Result::fail('雅本返回的金额或币种与订单不匹配', 502);
            }

            $income = $transaction['income_status_code'] ?? null;
            $outgoing = $transaction['outgoing_status_code'] ?? null;
            $refund = $transaction['refund_status_code'] ?? null;
            if (! $this->supportedState($income, $outgoing, $refund)) {
                return Result::fail('无法识别雅本返回的订单状态，请查询核实', 502);
            }
            $this->saveState($order, $income, $outgoing, $refund);

            return Result::success($this->format($order, true));
        } catch (YabandException|LockTimeoutException) {
            return Result::fail('暂无法查询雅本交易，请稍后重试', 503);
        } finally {
            $lock->release();
        }
    }

    /** 核对实际上游返回的订单金额及币种，兼容未携带这些字段的历史响应。 */
    private function matchesPaymentTerms(array $transaction, RemittanceOrder $order): bool
    {
        $amount = sprintf('%d.%02d', intdiv($order->principal_minor, 100), $order->principal_minor % 100);
        if (array_key_exists('amount', $transaction)
            && (! is_string($transaction['amount']) || $transaction['amount'] !== $amount)) {
            return false;
        }

        return ! array_key_exists('currency', $transaction) || $transaction['currency'] === 'EUR';
    }

    /** 三类状态按各自已文档化的枚举核对，未知值不能触发核销或成功通知。 */
    private function supportedState(mixed $income, mixed $outgoing, mixed $refund): bool
    {
        return in_array($income, ['00', '01', '02', '03', '04', '05', '06', '07', '08'], true)
            && in_array($outgoing, ['01', '02', '03', '04', '05', '06', '07'], true)
            && in_array($refund, ['00', '01', '02', '03', '04', '05', '06', '07'], true);
    }

    /** 同一事务同步订单及券，仅对核实后的关键状态变化发送一次消息。 */
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

    /** 按会员及员工视图返回业务字段，付款链接仅供本人在有效期内使用。 */
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

    /** 状态含义分别呈现，退款取消和未识别状态不显示为资金完成。 */
    public function statusLabel(RemittanceOrder $order): string
    {
        if (! $this->supportedState($order->income_code, $order->outgoing_code, $order->refund_code)) {
            return '等待核对状态';
        }

        return match (true) {
            $order->refund_code === '04' => '已退款',
            in_array($order->refund_code, ['01', '02', '03'], true) => '退款处理中',
            in_array($order->refund_code, ['05', '06'], true) => '退款异常',
            $order->refund_code === '07' => '退款已取消',
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
