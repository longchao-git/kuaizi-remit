import { SessionService } from '@/modules/auth/application/SessionService'
import { useAuthService } from './auth'

const sessionService = new SessionService(useAuthService())

export const useSessionService = () => sessionService
