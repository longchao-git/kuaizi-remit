import { get, post, put } from '@/request/http'
import type { Page } from './remitOperationsRepository'

export interface ClosureRecord {
  id: string
  member_id: string
  status: 'pending' | 'approved' | 'rejected'
  created_at: string
  reviewed_at: string | null
  reason: string | null
  review_note: string | null
  reviewed_by: string
  execution_status: 'not_started' | 'processing' | 'pending_confirmation' | 'completed'
  execution_note: string | null
  execution_started_at: string | null
  executed_at: string | null
  executed_by: string
  last_checked_at: string | null
  last_checked_by: string
}

const path = '/admin/remittance/closures'

export function loadClosureRequests(page: number, memberId: string, status: string) {
  return get<Page<ClosureRecord>>(path, { page, member_id: memberId || undefined, status: status || undefined })
}

export function reviewClosure(id: string, decision: 'approved' | 'rejected', note: string) {
  return put<ClosureRecord>(path + '/' + id, { decision, note })
}

export function executeClosure(id: string) {
  return post<ClosureRecord>(path + '/' + id + '/execute', { confirm: true })
}

export function verifyClosure(id: string) {
  return post<ClosureRecord>(path + '/' + id + '/verify')
}
