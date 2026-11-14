import type { GenderEnum } from './GenderEnum'

export interface UserSaveDTO {
  username: string
  password: string
  nickname?: string
  avatar?: string
  email?: string
  gender?: GenderEnum
  remark?: string
  roleIds?: string[]
}
