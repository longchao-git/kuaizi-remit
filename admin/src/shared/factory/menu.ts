import { MenuService } from '@/modules/menu/application/MenuService'
import { MenuRepository } from '@/modules/menu/repository/MenuRepository'

const menuRepository = new MenuRepository()
const menuService = new MenuService(menuRepository)

export const useMenuService = () => menuService
