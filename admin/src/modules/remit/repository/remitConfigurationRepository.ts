import { get, post, put } from '@/request/http'
import type { Page } from './remitOperationsRepository'

export type PaymentChannel = 'sepa' | 'applePay' | 'card'

export interface NoticeSettings {
  notice: string | null
  notice_enabled: boolean
}

export interface OperationsInput {
  revision_id: string
  announcement: { enabled: boolean; text: string | null }
  payment: Record<PaymentChannel, NoticeSettings>
}

export interface OperationsSettings extends OperationsInput {
  base_fee_minor: number
  first_order_free: boolean
  payment: Record<PaymentChannel, NoticeSettings & { basis_points: number }>
}

export interface ReconciliationBatch {
  id: string
  settlement_date: string
  filename: string
  imported_by: string | null
  created_at: string
  line_count: number
  matched_count: number
  difference_count: number
  pending_count: number
}

export interface ReconciliationLine {
  id: string
  order_reference: string
  imported_minor: number
  expected_minor: number | null
  imported_status: string | null
  expected_status: string | null
  result: 'matched' | 'missing' | 'amount' | 'status' | 'amount_status'
  reviewed_by: string | null
  reviewed_at: string | null
  review_note: string | null
}

export type LineFilter = 'all' | 'differences' | 'pending'

const base = '/admin/remittance'
const recon = base + '/reconciliation'

export const readOperations = () => get<OperationsSettings>(base + '/rules')
export const saveOperations = (input: OperationsInput) => put<OperationsSettings>(base + '/operations', input)
export const listBatches = (page: number, date: string, reference: string, status: string) =>
  get<Page<ReconciliationBatch>>(recon, {
    page, settlement_date: date || undefined, order_reference: reference || undefined, status: status || undefined,
  })
export const listLines = (batch: string, page: number, filter: LineFilter) =>
  get<Page<ReconciliationLine>>(recon + '/' + batch + '/lines', { page, filter })
export const reviewLine = (batch: string, line: string, note: string) =>
  put<ReconciliationLine>(recon + '/' + batch + '/lines/' + line, { note })
export const exportBatch = (batch: string) => get<Blob>(recon + '/' + batch + '/export', undefined, { responseType: 'blob' })

export function importBatch(file: File, date: string) {
  const data = new FormData()
  data.append('file', file)
  data.append('settlement_date', date)
  return post<{ id: string; reused: boolean }>(recon, data)
}
