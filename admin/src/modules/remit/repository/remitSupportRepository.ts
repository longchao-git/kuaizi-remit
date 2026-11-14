import { get, post } from '@/request/http'
import type { Page } from './remitOperationsRepository'

export type SupportMessage = { id: number; sender: 'staff' | 'member'; body: string; created_at: string }
export type Conversation = { member_id: string; member_name: string; last_message: SupportMessage; unread_count: number }
type History = { list: SupportMessage[]; next_after_id: number | null; has_more: boolean }

const conversationPath = (member: string) => `/admin/support/members/${member}`

export function listSupportConversations(page: number, unreadOnly: boolean) {
  return get<Page<Conversation>>('/admin/support/conversations', { page, unread_only: unreadOnly ? 1 : 0 })
}

export function getSupportHistory(member: string, after: number | null) {
  return get<History>(`${conversationPath(member)}/messages`, after === null ? undefined : { after_id: after })
}

export function sendSupportReply(member: string, id: string, body: string) {
  return post(`${conversationPath(member)}/messages`, { client_id: id, body })
}

export function markSupportRead(member: string, id: number) {
  return post(`${conversationPath(member)}/read`, { message_id: id })
}
