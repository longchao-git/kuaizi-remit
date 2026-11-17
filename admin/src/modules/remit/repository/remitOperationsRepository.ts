import { get, post } from '@/request/http'

export interface Page<T> {
  list: T[]
  total: number
  page: number
  page_size: number
}

export interface MemberRow {
  id: string
  name: string
  email: string | null
  masked_phone: string | null
  kyc_registered: boolean
  kyc_code: string | null
  created_at: string | null
}

export interface MemberOrderSummary {
  reference: string
  principal_minor: number
  status_label: string
  created_at: string
}

export interface NoticeDraft {
  request_id: string
  title: string
  body: string
}

export interface RuleRevision {
  id: string
  staff_name: string
  created_at: string
  settings: {
    announcement?: { enabled: boolean; text: string | null }
    base_fee_minor: number
    first_order_free: boolean
    spread_basis_points?: number
    payment: Record<string, { basis_points: number; notice?: string; notice_enabled?: boolean }>
    eta: Record<string, {
      threshold_minor: number; below: string; at_or_above: string
      bands?: { from_minor: number; until_minor: number | null; label: string; enabled: boolean }[]
    }>
  }
}

const base = '/admin/remittance'

export function listMembers(page: number, keyword: string) {
  return get<Page<MemberRow>>(`${base}/members`, { page, keyword })
}

export async function listRecentMemberOrders(member: string) {
  const result = await get<Page<MemberOrderSummary>>(`${base}/orders`, { page: 1, member_id: member })
  return result.list.slice(0, 5)
}

export function sendMemberNotice(member: string, draft: NoticeDraft) {
  return post(`${base}/members/${encodeURIComponent(member)}/notifications`, draft)
}

export function listRuleRevisions(page: number) {
  return get<Page<RuleRevision>>(`${base}/rules/revisions`, { page })
}
