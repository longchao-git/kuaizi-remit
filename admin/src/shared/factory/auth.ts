import { AuthService } from '@/modules/auth/application/AuthService'
import { AuthRepository } from '@/modules/auth/repository/AuthRepository'

const authRepository = new AuthRepository()
const authService = new AuthService(authRepository)

export const useAuthService = () => authService
