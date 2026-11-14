import type { GenderEnum } from './GenderEnum'
import type { UserTypeEnum } from './UserTypeEnum'

export interface UserVO {
  id: string
  createBy?: string
  createTime?: string
  updateBy?: string
  updateTime?: string

  username: string
  nickname?: string
  avatar?: string
  email?: string
  gender?: GenderEnum
  userType?: UserTypeEnum
  status?: boolean

  roleName?: string
  roleIds?: string[]
}

export function getUserTypeLabel(type?: UserTypeEnum): string {
  if (!type) return '-'
  switch (type) {
    case 'ADMIN':
      return '管理员'
    case 'NORMAL':
      return '普通用户'
    default:
      return '-'
  }
}

export function getGenderLabel(gender?: GenderEnum): string {
  if (!gender) return '-'
  switch (gender) {
    case 'MAN':
      return '男'
    case 'WOMAN':
      return '女'
    default:
      return '-'
  }
}
