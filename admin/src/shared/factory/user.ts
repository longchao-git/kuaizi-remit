import { UserService } from '@/modules/user/application/UserService'
import { UserRepository } from '@/modules/user/repository/UserRepository'

const userRepository = new UserRepository()
const userService = new UserService(userRepository)

export const useUserService = () => userService
