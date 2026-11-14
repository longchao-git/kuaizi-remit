<?php

namespace App\Repository\Remittance;

use App\Model\Remittance\RemittanceCoupon;
use App\Repository\AbstractRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** @extends AbstractRepository<RemittanceCoupon> */
final class RemittanceCouponRepository extends AbstractRepository
{
    protected function model(): RemittanceCoupon
    {
        return new RemittanceCoupon;
    }

    /** 员工查全部券，会员只查自己的券。 */
    public function page(int $page, ?int $member = null): LengthAwarePaginator
    {
        $query = $this->query()->orderByDesc('id');
        if ($member !== null) {
            $query->where('member_id', $member);
        }

        return $query->paginate(20, page: $page);
    }

    /** 发放到指定会员，或者创建有数据库唯一约束的随机兑换码。 */
    public function issue(string $requestId, array $attributes): RemittanceCoupon
    {
        return $this->query()->createOrFirst(['request_id' => $requestId], [
            ...$attributes,
            'code' => $attributes['member_id'] === null ? strtoupper(Str::random(20)) : null,
        ]);
    }

    public function issuedFor(string $requestId): ?RemittanceCoupon
    {
        return $this->query()->where('request_id', $requestId)->first();
    }

    /** 仅未兑换、有效且未撤销的码可以绑定会员。更新条件抵御并发兑换。 */
    public function claim(string $code, int $member): ?RemittanceCoupon
    {
        $this->active()->where('code', $code)->whereNull('member_id')
            ->update(['member_id' => $member, 'claimed_at' => now()]);

        return $this->active()->where('code', $code)->where('member_id', $member)->first();
    }

    /** 仅当前会员可以在首页看到有效且待领取的券。 */
    public function homeOffer(int $member): ?RemittanceCoupon
    {
        return $this->active()->where('home_popup', true)->where('member_id', $member)
            ->whereNull('claimed_at')->orderByDesc('id')->first();
    }

    /** 同一个会员重复领取时保留最初的领取时间。 */
    public function claimHomeOffer(int $id, int $member): ?RemittanceCoupon
    {
        $coupon = $this->active()->where('home_popup', true)->where('member_id', $member)->whereKey($id);
        (clone $coupon)->whereNull('claimed_at')->update(['claimed_at' => now()]);

        return $coupon->first();
    }

    /** 保存撤销操作人和时间，重复撤销保留原记录。 */
    public function revoke(int $id, int $staff): ?RemittanceCoupon
    {
        $this->query()->whereKey($id)->whereNull('revoked_at')->whereNull('order_reference')
            ->update(['revoked_at' => now(), 'revoked_by' => $staff]);

        return $this->query()->find($id);
    }

    /** 查询会员可在试算中使用的本人优惠券。 */
    public function ownedActive(int $id, int $member, bool $lock = false): ?RemittanceCoupon
    {
        return $this->active()->where('member_id', $member)
            ->where(fn (Builder $query) => $query->where('home_popup', false)->orWhereNotNull('claimed_at'))
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->find($id);
    }

    public function lockMember(int $member): void
    {
        DB::table('users')->where('id', $member)->lockForUpdate()->first(['id']);
    }

    public function firstOrderHeld(int $member): bool
    {
        return $this->query()->where('member_id', $member)->where('discount_bps', RemittanceCoupon::FIRST_ORDER_BPS)
            ->whereNotNull('order_reference')->whereNull('redeemed_at')->exists();
    }

    public function hold(int $coupon, int $member, string $order): bool
    {
        return $this->query()->whereKey($coupon)->where('member_id', $member)->whereNull('order_reference')
            ->update(['order_reference' => $order]) === 1;
    }

    /** 入账即核销，退款保留已发生的核销。确认未付款的终态才释放锁定。 */
    public function syncOrder(string $order, string $income, string $refund): void
    {
        $coupon = $this->query()->where('order_reference', $order)->whereNull('redeemed_at');
        if ($income === '00' || in_array($refund, ['01', '02', '03', '04'], true)) {
            $coupon->update(['redeemed_at' => now()]);
        } elseif (in_array($income, ['05', '06', '07', '08'], true) && $refund === '00') {
            $coupon->update(['order_reference' => null]);
        }
    }

    private function active(): Builder
    {
        return $this->query()->whereNull('revoked_at')->whereNull('order_reference')->where('expires_at', '>', now());
    }
}
