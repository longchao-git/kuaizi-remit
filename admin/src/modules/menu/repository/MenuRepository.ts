import { del, get, post, put } from '@/request/http';
import type { MenuVO } from '@/shared/types/menu';
import type { MenuSaveDTO } from '../domain/MenuSaveDTO';
import type { MenuUpdateDTO } from '../domain/MenuUpdateDTO';

export class MenuRepository {
  private readonly basePath = '/menu'

  /**
   * 获取菜单树
   */
  async getMenuTree(): Promise<MenuVO[]> {
    return await get<MenuVO[]>(`${this.basePath}/tree`);
  }

  /**
   * 保存菜单
   */
  async saveMenu(menu: MenuSaveDTO): Promise<void> {
    await post<void, MenuSaveDTO>(this.basePath, menu)
  }

  /**
   * 更新菜单
   */
  async updateMenu(menu: MenuUpdateDTO): Promise<void> {
    await put<void, MenuUpdateDTO>(this.basePath, menu)
  }

  /**
   * 删除菜单
   */
  async deleteMenu(id: string): Promise<void> {
    await del<void>(`${this.basePath}/${id}`)
  }
}
