import type { PageResult } from '@/shared/types/api/PageResult'
import type { RoleVO } from '@/modules/role/domain/RoleVO'
import { UserRepository } from '../repository/UserRepository'
import type { UserPageQuery } from '../domain/UserPageQuery'
import type { UserVO } from '../domain/UserVO'
import type { UserSaveDTO } from '../domain/UserSaveDTO'
import type { UserUpdateDTO } from '../domain/UserUpdateDTO'
import type { UserRoleUpdateDTO } from '../domain/UserRoleUpdateDTO'
import type { UserPasswordResetDTO } from '../domain/UserPasswordResetDTO'

export class UserService {
  constructor(private readonly repo: UserRepository) {}

  async getUserPage(params: UserPageQuery): Promise<PageResult<UserVO>> {
    return await this.repo.getUserPage(params)
  }

  async getById(id: string): Promise<UserVO> {
    return await this.repo.getById(id)
  }

  async save(data: UserSaveDTO): Promise<boolean> {
    if (!data.username || data.username.trim().length === 0) throw new Error('请输入用户名')
    if (!data.password || data.password.trim().length === 0) throw new Error('请输入密码')
    const passwordLength = data.password.trim().length
    if (passwordLength < 8 || passwordLength > 20) throw new Error('密码长度必须为 8-20 位')
    return await this.repo.saveUser(data)
  }

  async update(data: UserUpdateDTO): Promise<boolean> {
    if (!data.id) throw new Error('用户 ID 不能为空')
    return await this.repo.updateUser(data)
  }

  async delete(id: string): Promise<boolean> {
    if (!id) throw new Error('用户 ID 不能为空')
    return await this.repo.deleteUser(id)
  }

  async enable(id: string): Promise<boolean> {
    if (!id) throw new Error('用户 ID 不能为空')
    return await this.repo.enableUser(id)
  }

  async disable(id: string): Promise<boolean> {
    if (!id) throw new Error('用户 ID 不能为空')
    return await this.repo.disableUser(id)
  }

  async updateRoles(dto: UserRoleUpdateDTO): Promise<boolean> {
    if (!dto.userId) throw new Error('用户 ID 不能为空')
    return await this.repo.updateRoles(dto)
  }

  async resetPassword(id: string): Promise<boolean> {
    if (!id) throw new Error('用户 ID 不能为空')
    const dto: UserPasswordResetDTO = { id }
    return await this.repo.resetPassword(dto)
  }

  async getUserRoles(id: string): Promise<RoleVO[]> {
    if (!id) throw new Error('用户 ID 不能为空')
    return await this.repo.getUserRoles(id)
  }
}
