import type { GenderEnum } from './GenderEnum'

export interface UserUpdateDTO {
  id: string
  username?: string
  nickname?: string
  avatar?: string
  email?: string
  gender?: GenderEnum
  remark?: string
}
