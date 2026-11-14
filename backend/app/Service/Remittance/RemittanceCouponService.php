<?php

namespace App\Service\Remittance;

use App\Common\PaginationPayload;
use App\Common\Result;
use App\Model\Remittance\RemittanceCoupon;
use App\Model\Remittance\RemittanceCouponTemplate;
use App\Repository\Remittance\RemittanceCouponRepository;
use App\Repository\Remittance\RemittanceCouponTemplateRepository;
use App\Repository\System\UserRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Broadcast;

/** 管理一次性优惠码和会员优惠券。 */
#[Singleton]
final class RemittanceCouponService
{
    /** 复用会员仓储校验发放对象。 */
    public function __construct(
        private RemittanceCouponRepository $coupons,
        private UserRepository $users,
        private RemittanceCouponTemplateRepository $templates,
        private DatabaseManager $database,
    ) {}

    /** 读取员工券台账或当前会员的优惠券。 */
    public function page(int $page, ?int $member = null): Result
    {
        $rows = $this->coupons->page($page, $member);
        $items = array_map(fn (RemittanceCoupon $coupon) => $this->format($coupon, $member === null), $rows->items());

        return Result::success(PaginationPayload::from($items, $rows->total(), $page, $rows->perPage())->toArray());
    }

    /** 开券时只允许向非员工会员发放，或生成单次兑换码。 */
    public function issue(array $input, int $staff): Result
    {
        [$result, $notifyMember] = $this->database->transaction(fn () => $this->issueCoupon($input, $staff), attempts: 3);
        if ($notifyMember !== null) {
            rescue(fn () => Broadcast::private('users.'.$notifyMember)->as('coupons.changed')->with([])->sendNow());
        }

        return $result;
    }

    /** @return array{Result, int|null} */
    private function issueCoupon(array $input, int $staff): array
    {
        $memberId = $input['member_id'] ?? null;
        $homePopup = $input['delivery'] === 'home_popup';
        $templateId = $input['template_id'] ?? null;
        $requestId = strtolower($input['request_id']);
        $identity = $templateId === null
            ? [$staff, $memberId, $input['title'], $input['discount_bps'], $input['valid_days']]
            : [$staff, $memberId, 'template', $templateId];
        if ($homePopup) {
            $identity[] = 'home_popup';
        }
        $fingerprint = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
        $template = $templateId === null ? null : $this->templates->find($templateId, lock: true);
        $existing = $this->coupons->issuedFor($requestId);
        if ($existing !== null) {
            return [$this->issuedResult($existing, $fingerprint), null];
        }
        if ($memberId !== null && ! $this->users->memberExists($memberId)) {
            return [Result::fail('会员不存在', 404), null];
        }
        if ($templateId !== null && ! $template?->enabled) {
            return [Result::fail('优惠券模板不可用', 409), null];
        }

        $coupon = $this->coupons->issue($requestId, [
            'issue_fingerprint' => $fingerprint,
            'member_id' => $memberId,
            'home_popup' => $homePopup,
            'issued_by' => $staff,
            'title' => $template?->title ?? $input['title'],
            'discount_bps' => $template?->discount_bps ?? $input['discount_bps'],
            'expires_at' => now()->addDays($template?->valid_days ?? $input['valid_days']),
            'template_id' => $templateId,
            'payout_method' => $template?->payout_method,
        ]);

        return [$this->issuedResult($coupon, $fingerprint), $homePopup && $coupon->wasRecentlyCreated ? $memberId : null];
    }

    private function issuedResult(RemittanceCoupon $coupon, string $fingerprint): Result
    {
        if ($coupon->issue_fingerprint !== $fingerprint) {
            return Result::fail('发放编号已用于其他请求', 409);
        }

        return Result::success($this->format($coupon, true), '已发放优惠券');
    }

    public function templates(int $page, bool $enabledOnly): Result
    {
        $rows = $this->templates->page($page, $enabledOnly);
        $list = array_map($this->formatTemplate(...), $rows->items());

        return Result::success(PaginationPayload::from($list, $rows->total(), $page, $rows->perPage())->toArray());
    }

    public function saveTemplate(array $input, ?int $id): Result
    {
        if ($id === null) {
            return Result::success($this->formatTemplate($this->templates->create($input)));
        }
        $template = $this->templates->find($id);
        if ($template === null) {
            return Result::fail('优惠券模板不存在', 404);
        }

        return Result::success($this->formatTemplate($this->templates->update($template, $input)));
    }

    private function formatTemplate(RemittanceCouponTemplate $template): array
    {
        return [
            'id' => (string) $template->id,
            'title' => $template->title,
            'coupon_type' => RemittanceCoupon::typeForDiscount($template->discount_bps),
            'discount_bps' => $template->discount_bps,
            'valid_days' => $template->valid_days,
            'payout_method' => $template->payout_method,
            'enabled' => $template->enabled,
        ];
    }

    /** 兑换成功即绑定当前账号，同一账号重试返回原券。 */
    public function claim(string $code, int $member): Result
    {
        $coupon = $this->coupons->claim(strtoupper($code), $member);

        return $coupon === null
            ? Result::fail('优惠码不可用', 409)
            : Result::success($this->format($coupon, false), '已领取优惠券');
    }

    /** 首页只展示最近一张可领取券，其余可从优惠券列表领取。 */
    public function homeOffer(int $member): Result
    {
        $coupon = $this->coupons->homeOffer($member);

        return Result::success(['offer' => $coupon === null ? null : $this->format($coupon, false)]);
    }

    public function claimHomeOffer(int $id, int $member): Result
    {
        $coupon = $this->coupons->claimHomeOffer($id, $member);

        return $coupon === null
            ? Result::fail('这张优惠券已无法领取', 409)
            : Result::success($this->format($coupon, false), '已领取优惠券');
    }

    /** 禁止已撤销券继续兑换和参与新试算。 */
    public function revoke(int $id, int $staff): Result
    {
        $coupon = $this->coupons->revoke($id, $staff);

        if ($coupon === null) {
            return Result::fail('优惠券不存在', 404);
        }

        return $coupon->order_reference !== null
            ? Result::fail('优惠券已关联汇款订单，无法撤销', 409)
            : Result::success($this->format($coupon, true));
    }

    /** 会员端省略兑换码及员工操作归属。 */
    private function format(RemittanceCoupon $coupon, bool $staff): array
    {
        $status = match (true) {
            $coupon->redeemed_at !== null => 'redeemed',
            $coupon->order_reference !== null => 'reserved',
            $coupon->revoked_at !== null => 'revoked',
            $coupon->expires_at->lte(now()) => 'expired',
            $coupon->member_id === null => 'unclaimed',
            $coupon->home_popup && $coupon->claimed_at === null => 'pending_claim',
            default => 'available',
        };
        $data = [
            'id' => (string) $coupon->id,
            'title' => $coupon->title,
            'coupon_type' => RemittanceCoupon::typeForDiscount($coupon->discount_bps),
            'discount_bps' => $coupon->discount_bps,
            'status' => $status,
            'expires_at' => $coupon->expires_at->toISOString(),
            'payout_method' => $coupon->payout_method,
        ];
        if ($staff) {
            $data += [
                'member_id' => $coupon->member_id === null ? null : (string) $coupon->member_id,
                'delivery' => $coupon->home_popup ? 'home_popup' : ($coupon->code === null ? 'direct' : 'code'),
                'code' => $status === 'unclaimed' ? $coupon->code : null,
                'issued_by' => $coupon->issued_by,
                'revoked_by' => $coupon->revoked_by,
                'claimed_at' => $coupon->claimed_at?->toISOString(),
                'revoked_at' => $coupon->revoked_at?->toISOString(),
                'created_at' => $coupon->created_at->toISOString(),
                'order_reference' => $coupon->order_reference,
            ];
        }

        return $data;
    }
}
