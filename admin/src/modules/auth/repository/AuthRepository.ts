import { post, get, put } from '@/request/http';
import type { LoginForm } from '../domain/LoginForm';
import type { LoginResponse } from '../domain/LoginResponse';
import type { UserInfo } from '../domain/UserInfo';
import type { MenuVO } from '@/shared/types/menu';

export class AuthRepository {
  private readonly basePath = '/user'

  /**
   * 用户登录
   * @param loginForm 登录表单数据
   * @returns 登录响应
   */
  async login(loginForm: LoginForm): Promise<LoginResponse> {
    // 对接当前后端：POST /user/login
    return await post<LoginResponse>(`${this.basePath}/login`, loginForm);
  }

  /**
   * 获取当前用户信息
   * @returns 用户信息
   */
  async getCurrentUser(): Promise<UserInfo> {
    return await get<UserInfo>(`${this.basePath}/current`);
  }

  /**
   * 获取当前用户菜单权限
   * @returns 用户菜单列表
   */
  async getUserMenus(): Promise<MenuVO[]> {
    return await get<MenuVO[]>(`${this.basePath}/menus`);
  }

  /**
   * 获取当前用户权限码列表
   * @returns 权限码列表
   */
  async getUserPermissions(): Promise<string[]> {
    return await get<string[]>(`${this.basePath}/permissions`);
  }

  /**
   * 用户登出
   */
  async logout(): Promise<void> {
    await post<void>(`${this.basePath}/logout`);
  }

  /**
   * 修改密码
   * @param passwordData 密码数据
   */
  async changePassword(passwordData: { oldPassword: string; newPassword: string }): Promise<void> {
    await put<void>(`${this.basePath}/current/password`, passwordData);
  }
}
