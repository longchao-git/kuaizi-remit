export interface MemberCoupon {
  payout_method?: 'wechat' | 'bank' | null;
  id: string;
  title: string;
  discount_bps: number;
  coupon_type: 'first_order_free' | 'discount';
  status: 'available' | 'pending_claim' | 'expired' | 'revoked' | 'reserved' | 'redeemed';
  expires_at: string;
}

export function couponBenefit(coupon: MemberCoupon): string {
  return coupon.coupon_type === 'first_order_free'
    ? '新人首单免手续费'
    : '基础手续费减免 ' + coupon.discount_bps / 100 + '%';
}
