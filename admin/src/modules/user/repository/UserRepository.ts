import { del, get, post, put } from '@/request/http'
import type { PageResult } from '@/shared/types/api/PageResult'
import type { PageVO } from '@/shared/types/api/PageVO'
import type { RoleVO } from '@/modules/role/domain/RoleVO'

import type { UserVO } from '../domain/UserVO'
import type { UserPageQuery } from '../domain/UserPageQuery'
import type { UserSaveDTO } from '../domain/UserSaveDTO'
import type { UserUpdateDTO } from '../domain/UserUpdateDTO'
import type { UserRoleUpdateDTO } from '../domain/UserRoleUpdateDTO'
import type { UserPasswordResetDTO } from '../domain/UserPasswordResetDTO'

export class UserRepository {
  private readonly basePath = '/user'

  async getUserPage(params: UserPageQuery): Promise<PageResult<UserVO>> {
    const page = await get<PageVO<UserVO>>(`${this.basePath}/page`, params)
    const size = Number(page?.size ?? 10) || 10
    const total = Number(page?.total ?? 0) || 0
    return {
      records: page?.records ?? [],
      total,
      current: Number(page?.current ?? 1) || 1,
      size,
      pages: size > 0 ? Math.ceil(total / size) : 0
    }
  }

  async getById(id: string): Promise<UserVO> {
    return await get<UserVO>(`${this.basePath}/${id}`)
  }

  async saveUser(data: UserSaveDTO): Promise<boolean> {
    return await post<boolean, UserSaveDTO>(this.basePath, data)
  }

  async updateUser(data: UserUpdateDTO): Promise<boolean> {
    return await put<boolean, UserUpdateDTO>(this.basePath, data)
  }

  async deleteUser(id: string): Promise<boolean> {
    return await del<boolean>(`${this.basePath}/${id}`)
  }

  async enableUser(id: string): Promise<boolean> {
    return await put<boolean, undefined>(`${this.basePath}/${id}/enable`, undefined)
  }

  async disableUser(id: string): Promise<boolean> {
    return await put<boolean, undefined>(`${this.basePath}/${id}/disable`, undefined)
  }

  async updateRoles(dto: UserRoleUpdateDTO): Promise<boolean> {
    return await put<boolean, UserRoleUpdateDTO>(`${this.basePath}/roles`, dto)
  }

  async resetPassword(dto: UserPasswordResetDTO): Promise<boolean> {
    return await put<boolean, UserPasswordResetDTO>(`${this.basePath}/password/reset`, dto)
  }

  async getUserRoles(id: string): Promise<RoleVO[]> {
    return await get<RoleVO[]>(`${this.basePath}/${id}/roles`)
  }
}
