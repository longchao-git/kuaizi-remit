import { MenuRepository } from '../repository/MenuRepository';
import type { MenuVO } from '@/shared/types/menu';
import type { MenuSaveDTO } from '../domain/MenuSaveDTO';
import type { MenuUpdateDTO } from '../domain/MenuUpdateDTO';
import { normalizeMenuTree, resolveMenuRemark } from '@/shared/utils/menuTree'

export class MenuService {
  constructor(private readonly menuRepository: MenuRepository) {}

  async getMenuTree(): Promise<MenuVO[]> {
    return normalizeMenuTree(await this.menuRepository.getMenuTree());
  }

  async saveMenu(menu: MenuSaveDTO): Promise<void> {
    await this.menuRepository.saveMenu(this.toRepositoryPayload(menu));
  }

  async updateMenu(menu: MenuUpdateDTO): Promise<void> {
    await this.menuRepository.updateMenu(this.toRepositoryPayload(menu));
  }

  async deleteMenu(id: string): Promise<void> {
    await this.menuRepository.deleteMenu(id);
  }

  /**
   * 校验权限标识是否重复（基于当前菜单树数据）
   */
  isPermissionDuplicate(menuTree: MenuVO[], permission: string, currentId?: string | null): boolean {
    const valueStr = String(permission ?? '').trim();
    if (!valueStr) return false;

    const stack: MenuVO[] = [...(menuTree || [])];
    while (stack.length > 0) {
      const node = stack.pop() as MenuVO;
      const nodePerm = String(node.permission ?? '');
      if (nodePerm === valueStr && node.id !== (currentId || undefined)) return true;
      if (node.children && node.children.length > 0) {
        stack.push(...node.children);
      }
    }
    return false;
  }

  private toRepositoryPayload<T extends MenuSaveDTO | MenuUpdateDTO>(menu: T): T {
    const { visible, ...rest } = menu
    return {
      ...rest,
      remark: resolveMenuRemark(visible, rest.remark)
    } as T
  }
}
