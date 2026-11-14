import { RoleRepository } from '../repository/RoleRepository'
import type { RoleQueryParams } from '../domain/RoleQueryParams'
import type { RoleVO } from '../domain/RoleVO'
import type { RoleSaveDTO } from '../domain/RoleSaveDTO'
import type { RoleUpdateDTO } from '../domain/RoleUpdateDTO'
import type { RoleCloneDTO } from '../domain/RoleCloneDTO'
import type { RoleMenuUpdateDTO } from '../domain/RoleMenuUpdateDTO'
import type { MenuVO } from '@/shared/types/menu'
import type { PageResult } from '@/shared/types/api/PageResult'

export class RoleService {
  constructor(private readonly roleRepository: RoleRepository) {}

  async getRolePage(params: RoleQueryParams): Promise<PageResult<RoleVO>> {
    return this.roleRepository.getRolePage(params)
  }

  async saveRole(role: RoleSaveDTO): Promise<void> {
    await this.roleRepository.saveRole(role)
  }

  async updateRole(role: RoleUpdateDTO): Promise<void> {
    await this.roleRepository.updateRole(role)
  }

  async deleteRole(id: string): Promise<void> {
    await this.roleRepository.deleteRole(id)
  }

  async cloneRole(dto: RoleCloneDTO): Promise<boolean> {
    if (!dto.sourceRoleId) throw new Error('角色 ID 不能为空')
    if (!dto.name || dto.name.trim().length === 0) throw new Error('请输入角色名称')
    return await this.roleRepository.cloneRole(dto)
  }

  async getRoleById(id: string): Promise<RoleVO> {
    return this.roleRepository.getRoleById(id)
  }

  async getRoleOptions(): Promise<RoleVO[]> {
    return this.roleRepository.getRoleOptions()
  }

  async getMenuOptions(): Promise<MenuVO[]> {
    return this.roleRepository.getMenuOptions()
  }

  async getRoleMenuIds(roleId: string): Promise<string[]> {
    if (!roleId) throw new Error('角色 ID 不能为空')
    return this.roleRepository.getRoleMenuIds(roleId)
  }

  async updateRoleMenus(dto: RoleMenuUpdateDTO): Promise<void> {
    if (!dto.roleId) throw new Error('角色 ID 不能为空')
    if (!Array.isArray(dto.menuIds)) throw new Error('菜单权限格式不正确')
    await this.roleRepository.updateRoleMenus(dto)
  }

  async saveOrUpdateRole(data: (RoleSaveDTO | RoleUpdateDTO) & { id?: string }): Promise<void> {
    if (!data.name || String(data.name).trim().length === 0) {
      throw new Error('请输入角色名称')
    }
    if (data.sort === undefined || data.sort === null) {
      throw new Error('请输入排序号')
    }
    if (data.menuIds !== undefined && !Array.isArray(data.menuIds)) {
      throw new Error('菜单权限格式不正确')
    }

    if (data.id) {
      await this.roleRepository.updateRole(data as RoleUpdateDTO)
    } else {
      await this.roleRepository.saveRole(data as RoleSaveDTO)
    }
  }
}
