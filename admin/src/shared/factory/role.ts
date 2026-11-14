import { RoleService } from '@/modules/role/application/RoleService'
import { RoleRepository } from '@/modules/role/repository/RoleRepository'

const roleRepository = new RoleRepository()
const roleService = new RoleService(roleRepository)

export const useRoleService = () => roleService
