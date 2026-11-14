import type { UserInfo } from './UserInfo'
import type { MenuVO } from '@/shared/types/menu'

export interface SessionContext {
  profile: UserInfo | null
  menus: MenuVO[]
  permissions: string[]
}
