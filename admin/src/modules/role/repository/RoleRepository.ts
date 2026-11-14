import type { RoleQueryParams } from '../domain/RoleQueryParams'
import type { RoleVO } from '../domain/RoleVO'
import type { RoleSaveDTO } from '../domain/RoleSaveDTO'
import type { RoleUpdateDTO } from '../domain/RoleUpdateDTO'
import type { RoleCloneDTO } from '../domain/RoleCloneDTO'
import type { RoleMenuUpdateDTO } from '../domain/RoleMenuUpdateDTO'
import type { PageResult } from '@/shared/types/api/PageResult'
import type { PageVO } from '@/shared/types/api/PageVO'
import type { MenuVO } from '@/shared/types/menu'
import { del, get, post, put } from '@/request/http'

export class RoleRepository {
  private readonly basePath = '/role'

  async getRolePage(params: RoleQueryParams): Promise<PageResult<RoleVO>> {
    const page = await get<PageVO<RoleVO>>(`${this.basePath}/page`, params)
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

  async saveRole(role: RoleSaveDTO): Promise<void> {
    await post<void, RoleSaveDTO>(this.basePath, role)
  }

  async updateRole(role: RoleUpdateDTO): Promise<void> {
    await put<void, RoleUpdateDTO>(this.basePath, role)
  }

  async deleteRole(id: string): Promise<void> {
    await del<void>(`${this.basePath}/${id}`)
  }

  async getRoleById(id: string): Promise<RoleVO> {
    return await get<RoleVO>(`${this.basePath}/${id}`)
  }

  async getRoleOptions(): Promise<RoleVO[]> {
    return await get<RoleVO[]>(`${this.basePath}/options`)
  }

  async getMenuOptions(): Promise<MenuVO[]> {
    return await get<MenuVO[]>(`${this.basePath}/menu-options`)
  }

  async getRoleMenuIds(roleId: string): Promise<string[]> {
    return await get<string[]>(`${this.basePath}/menu/${roleId}`)
  }

  async updateRoleMenus(dto: RoleMenuUpdateDTO): Promise<void> {
    await put<void, RoleMenuUpdateDTO>(`${this.basePath}/menu`, dto)
  }

  async cloneRole(dto: RoleCloneDTO): Promise<boolean> {
    return await post<boolean, RoleCloneDTO>(`${this.basePath}/clone`, dto)
  }
}
