import { AuthRepository } from '../repository/AuthRepository'
import type { LoginForm } from '../domain/LoginForm'
import type { LoginResponse } from '../domain/LoginResponse'
import type { UserInfo } from '../domain/UserInfo'
import { validateUsername, validatePassword, validateAgreement } from '../domain/utils'
import type { MenuVO } from '@/shared/types/menu'
import { getErrorMessage } from '@/utils/error'

function preserveError(error: unknown, fallback: string): Error {
  return error instanceof Error ? error : new Error(getErrorMessage(error, fallback))
}

export class AuthService {
  constructor(private readonly repo: AuthRepository) {}

  async login(loginForm: LoginForm): Promise<LoginResponse> {
    if (!validateUsername(loginForm.username)) throw new Error('请输入账号')
    if (!validatePassword(loginForm.password)) throw new Error('请输入密码')

    try {
      const response = await this.repo.login(loginForm)
      if (!response?.token) throw new Error('登录响应无效')
      return response
    } catch (error) {
      throw preserveError(error, '登录失败')
    }
  }

  async getCurrentUser(): Promise<UserInfo> {
    try {
      return await this.repo.getCurrentUser()
    } catch (error) {
      throw preserveError(error, '获取用户信息失败')
    }
  }

  async getUserMenus(): Promise<MenuVO[]> {
    try {
      return await this.repo.getUserMenus()
    } catch (error) {
      throw preserveError(error, '获取用户菜单失败')
    }
  }

  async getUserPermissions(): Promise<string[]> {
    try {
      return await this.repo.getUserPermissions()
    } catch (error) {
      throw preserveError(error, '获取用户权限失败')
    }
  }

  validateUserAgreement(agreed: boolean): void {
    if (!validateAgreement(agreed)) throw new Error('请先同意用户协议和隐私政策')
  }

  async logout(): Promise<void> {
    await this.repo.logout().catch(() => undefined)
  }

}
