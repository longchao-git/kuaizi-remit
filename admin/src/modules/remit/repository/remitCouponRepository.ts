import { get, post, put } from '@/request/http'
import type { Page } from './remitOperationsRepository'

export interface Coupon {
  id: string
  title: string
  discount_bps: number
  coupon_type: CouponType
  order_reference: string | null
  status: string
  expires_at: string
  code: string | null
  member_id: string | null
  delivery: CouponDelivery | 'direct'
  payout_method: PayoutMethod
}

export type PayoutMethod = 'wechat' | 'bank' | null
export type CouponDelivery = 'home_popup' | 'code'
export type CouponType = 'first_order_free' | 'discount'
export const couponTypeLabels: Record<CouponType, string> = { first_order_free: '新人首单免手续费', discount: '折扣券' }
export const channelLabels: Record<string, string> = { all: '全部渠道', wechat: '微信收款', bank: '银行卡收款' }
export interface TemplateDraft {
  title: string
  discount_bps: number
  valid_days: number
  payout_method: PayoutMethod
  enabled: boolean
}
export interface CouponTemplate extends TemplateDraft { id: string; coupon_type: CouponType }

type CouponIssue = { request_id: string; delivery: CouponDelivery; member_id: number | null } & (
  { template_id: number } | Pick<TemplateDraft, 'title' | 'discount_bps' | 'valid_days'>
)
const path = '/admin/remittance/coupons'

export function listCoupons(page: number) {
  return get<Page<Coupon>>(path, { page })
}

export function issueCoupon(input: CouponIssue) {
  return post<Coupon>(path, input)
}

export function listCouponTemplates(page: number) {
  return get<Page<CouponTemplate>>(`${path}/templates`, { page })
}

export function saveCouponTemplate(id: string | undefined, draft: TemplateDraft) {
  return id
    ? put<CouponTemplate>(`${path}/templates/${encodeURIComponent(id)}`, draft)
    : post<CouponTemplate>(`${path}/templates`, draft)
}

export function revokeCoupon(id: string) {
  return post<Coupon>(`${path}/${encodeURIComponent(id)}/revoke`)
}
