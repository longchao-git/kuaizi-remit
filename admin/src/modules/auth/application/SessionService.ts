import { useUserStore } from '@/stores/user'
import { normalizeMenuTree } from '@/shared/utils/menuTree'
import type { LoginForm } from '../domain/LoginForm'
import type { SessionContext } from '../domain/SessionContext'
import { AuthService } from './AuthService'

export class SessionService {
  constructor(private readonly authService: AuthService) {}

  async loadCurrentSession(): Promise<SessionContext> {
    const [profile, menus, permissions] = await Promise.all([
      this.authService.getCurrentUser(),
      this.authService.getUserMenus(),
      this.authService.getUserPermissions()
    ])

    return {
      profile,
      menus: normalizeMenuTree(menus),
      permissions: Array.isArray(permissions) ? permissions : []
    }
  }

  async initializeSession(): Promise<SessionContext> {
    const userStore = useUserStore()
    const session = await this.loadCurrentSession()
    userStore.setSession(session)
    userStore.setRoutesLoaded(false)
    return session
  }

  async loginWithInitialization(loginForm: LoginForm, agreed: boolean): Promise<SessionContext> {
    const userStore = useUserStore()
    this.authService.validateUserAgreement(agreed)
    const response = await this.authService.login(loginForm)
    userStore.setToken(response.token)

    try {
      return await this.initializeSession()
    } catch (error) {
      this.clearSession()
      throw error
    }
  }

  async logout(): Promise<void> {
    await this.authService.logout().catch(() => undefined)
    this.clearSession()
  }

  handleUnauthorized(): void {
    this.clearSession()
  }

  clearSession(): void {
    const userStore = useUserStore()
    userStore.clearSession()
  }
}
