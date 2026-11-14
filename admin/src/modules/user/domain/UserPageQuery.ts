import type { BaseQueryParams } from '@/shared/types/api/BaseQueryParams'
import type { UserTypeEnum } from './UserTypeEnum'

export interface UserPageQuery extends BaseQueryParams {
  username?: string
  nickname?: string
  email?: string
  status?: boolean
  userType?: UserTypeEnum
}
