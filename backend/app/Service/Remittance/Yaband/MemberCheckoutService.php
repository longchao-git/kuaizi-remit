<?php

namespace App\Service\Remittance\Yaband;

use App\Common\Result;
use App\Model\Remittance\RemittanceCoupon;
use App\Model\Remittance\RemittanceOrder;
use App\Model\System\User;
use App\Repository\Remittance\MemberClosureRepository;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceOrderRepository;
use App\Repository\Remittance\RemittanceQuoteRecordRepository;
use App\Repository\Remittance\YabandRemitMemberRepository;
use App\Service\Remittance\Yaband\Contract\YabandCheckoutTerms;
use App\Service\Remittance\Yaband\Contract\YabandRemitSigner;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use JsonException;
use RuntimeException;

#[Singleton]
final class MemberCheckoutService
{
    public function __construct(
        private RemittanceQuoteRecordRepository $quotes,
        private RemittanceOrderRepository $orders,
        private YabandRemitMemberRepository $members,
        private YabandRemitClient $yaband,
        private RemittanceCouponRepository $coupons,
        private MemberClosureRepository $closures,
        private DatabaseManager $database,
    ) {}

    /** 查询结算适配器提供的本人账户，用于下单确认时选择。 */
    public function accounts(User $member, string $payoutMethod): Result
    {
        if (! app()->bound(YabandCheckoutTerms::class)) {
            return Result::fail('本人收款账户核验尚未开通', 503);
        }

        try {
            $rows = app(YabandCheckoutTerms::class)->accounts($member, $payoutMethod);
            $safe = array_map(static fn (array $row): array => ['reference' => $row['reference'], 'label' => $row['label']], $rows);

            return Result::success($safe);
        } catch (YabandException) {
            return Result::fail('暂无法读取本人收款账户', 503);
        }
    }

    /** 每份已锁定报价只产生一个订单，重试沿用原商户订单号和支付请求编号。 */
    public function submit(User $member, string $quoteReference, string $account, string $ip): Result
    {
        $quoteReference = strtolower($quoteReference);
        $quote = $this->quotes->findByReference($quoteReference);
        if ($quote === null || (int) $quote->member_id !== (int) $member->id) {
            return Result::fail('报价不存在', 404);
        }
        $snapshot = $quote->snapshot;
        if (($snapshot['mode'] ?? null) !== 'locked' || ($snapshot['can_submit'] ?? null) !== true) {
            return Result::fail('试算报价不能用于正式下单', 409);
        }
        if (! app()->bound(YabandCheckoutTerms::class) || ! app()->bound(YabandRemitSigner::class)) {
            return Result::fail('正式汇款通道尚未开通', 503);
        }

        $lock = Cache::lock('remittance-checkout:'.$quoteReference, 75);
        if (! $lock->get()) {
            return Result::fail('订单正在提交，请稍后查询', 409);
        }
        try {
            $order = $this->orders->getForQuote((int) $member->id, $quoteReference);
            if (! is_int($snapshot['expires_at'] ?? null) || $snapshot['expires_at'] <= now()->timestamp) {
                return $order?->provider_trade_id !== null
                    ? Result::success(['reference' => $order->reference, 'payment_url' => null])
                    : Result::fail('锁汇已过期，请重新报价', 409);
            }
            $link = $this->members->findFor((int) $member->id);
            if ($link?->uid === null || ! ($link->statuses['kyc_passed'] ?? false) || ! ($link->statuses['remittance_enabled'] ?? false)) {
                return Result::fail('请先完成身份认证并开通汇款', 409);
            }
            $prepared = app(YabandCheckoutTerms::class)->prepare($member, $snapshot, $account, $ip);
            $fields = [...$prepared['fields'], 'uid' => $link->uid, 'currency' => 'EUR'];
            unset($fields['order_id'], $fields['request_id'], $fields['type'], $fields['pay_method']);
            $fields['amount'] = $this->decimal($snapshot['principal_minor']);
            $hash = hash_hmac('sha256', json_encode([$account, $prepared['white_label'], $fields], JSON_THROW_ON_ERROR), (string) config('app.key'));
            $order ??= $this->reserve((int) $member->id, $quoteReference, $snapshot, $prepared['white_label'], $hash);
            if ($order instanceof Result) {
                return $order;
            }
            if ((int) $order->member_id !== (int) $member->id || ! hash_equals($order->payment_request_hash ?? '', $hash)) {
                return Result::fail('支付请求与原订单不一致，请先查询处理结果', 409);
            }
            if ($order->provider_trade_id !== null) {
                return $this->success($order);
            }
            if (! $order->wasRecentlyCreated && ($prepared['replay_safe'] ?? false) !== true) {
                return Result::fail('原支付请求尚未确认，请联系客户服务查询该笔订单', 409);
            }

            $result = $this->yaband->submitPayment([
                ...$fields, 'order_id' => $order->reference, 'request_id' => $order->payment_request_id,
            ], $order->white_label);
            $trade = $result['trade_id'] ?? null;
            $url = $result['url'] ?? null;
            if (($result['order_id'] ?? null) !== $order->reference || ! is_string($trade) || trim($trade) === '' || strlen($trade) > 64) {
                return Result::fail('雅本支付响应与本地订单不匹配，请查询处理结果', 502);
            }
            if (! is_string($url) || strlen($url) > 2048 || ! filter_var($url, FILTER_VALIDATE_URL)
                || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null) {
                $url = null;
            }
            $this->orders->savePayment($order, $trade, $url);

            return $this->success($order);
        } catch (YabandException|LockTimeoutException|JsonException) {
            return Result::fail('暂无法确认支付请求，请查询订单或使用原条件重试', 503);
        } finally {
            $lock->release();
        }
    }

    private function reserve(int $member, string $quote, array $snapshot, bool $whiteLabel, string $hash): RemittanceOrder|Result
    {
        return $this->database->transaction(function () use ($member, $quote, $snapshot, $whiteLabel, $hash): RemittanceOrder|Result {
            $this->coupons->lockMember($member);
            $existing = $this->orders->getForQuote($member, $quote);
            if ($existing !== null) {
                return $existing;
            }
            if ($this->closures->approved($member)) {
                return Result::fail('账号注销申请已通过审核，暂不能创建新汇款订单', 409);
            }
            if ($this->coupons->firstOrderHeld($member)) {
                return Result::fail('已有使用首单券的汇款订单，请先查看该订单', 409);
            }

            $couponId = $snapshot['coupon_id'] ?? null;
            if ($couponId !== null) {
                $coupon = $this->coupons->ownedActive($couponId, $member, lock: true);
                if ($coupon === null || ($coupon->payout_method !== null && $coupon->payout_method !== $snapshot['payout_method'])) {
                    return Result::fail('优惠券已不可用，请重新报价', 409);
                }
                if ($coupon->discount_bps === RemittanceCoupon::FIRST_ORDER_BPS && $this->orders->hasPaidOrPending($member)) {
                    return Result::fail('新人首单券仅适用于首笔汇款，请重新报价', 409);
                }
            } elseif (($snapshot['discount_minor'] ?? 0) > 0) {
                return Result::fail('优惠报价缺少关联券，请重新报价', 409);
            }

            $order = $this->orders->reserve($member, $quote, $snapshot, $whiteLabel, $hash);
            if ($couponId !== null && ! $this->coupons->hold($couponId, $member, $order->reference)) {
                throw new RuntimeException('无法锁定订单优惠券。');
            }

            return $order;
        }, attempts: 3);
    }

    private function decimal(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    private function success(RemittanceOrder $order): Result
    {
        $payable = in_array($order->income_code, ['02', '03'], true) && $order->refund_code === '00';

        return Result::success(['reference' => $order->reference, 'payment_url' => $payable ? $order->payment_url : null]);
    }
}
