import { get, put } from '@/request/http'
import type { Page } from './remitOperationsRepository'

export type AssignableRole = 'operations' | 'support' | 'read_only' | 'finance'
export type Staff = { id: string; name: string; email: string; role: AssignableRole | 'administrator' | 'legacy' }
export type RoleAudit = { id: number; operator_name: string; target_name: string; before_role: AssignableRole | null; after_role: AssignableRole; created_at: string }

const path = '/admin/remittance/staff'

export function listStaff(page: number) {
  return get<Page<Staff>>(path, { page })
}

export function listRoleAudits(page: number) {
  return get<Page<RoleAudit>>(`${path}/role-audits`, { page })
}

export function assignRole(id: string, role: AssignableRole) {
  return put(`${path}/${encodeURIComponent(id)}/role`, { role })
}
