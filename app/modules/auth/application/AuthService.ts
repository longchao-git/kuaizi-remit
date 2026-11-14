import { navigateToPostLoginTab, redirectToLoginPage } from '@/app/navigation/entryResolver';
import { getErrorMessage } from '@/shared/utils/error';
import type { CurrentUser } from '@/modules/profile/domain/CurrentUser';
import type { LoginForm } from '../domain/LoginForm';
import type { LoginResponse } from '../domain/LoginResponse';
import { AuthRepository } from '../repository/AuthRepository';
import { sessionService, type SessionService } from '@/shared/session/application/SessionService';

export class AuthService {
  constructor(
    private readonly repo: AuthRepository,
    private readonly session: SessionService
  ) {}

  private validateAgreement(agreed: boolean): void {
    if (!agreed) {
      throw new Error('请勾选用户协议及隐私条款');
    }
  }

  private async createWechatLoginForm(): Promise<LoginForm> {
    return await new Promise<LoginForm>((resolve, reject) => {
      // #ifdef MP-WEIXIN
      uni.login({
        provider: 'weixin',
        success: (result: UniApp.LoginRes) => {
          if (!result.code) {
            reject(new Error('获取微信登录凭证失败'));
            return;
          }
          resolve({ code: result.code });
        },
        fail: () => reject(new Error('获取微信登录凭证失败'))
      });
      // #endif

      // #ifndef MP-WEIXIN
      reject(new Error('请在微信小程序环境中使用微信登录'));
      // #endif
    });
  }

  async login(agreed: boolean): Promise<LoginResponse> {
    this.validateAgreement(agreed);
    return await this.performWechatLogin();
  }

  async tryAutoLogin(): Promise<CurrentUser | null> {
    if (!this.session.hasLoginMemory()) {
      return null;
    }

    try {
      const response = await this.performWechatLogin();
      return response.requiresMobileBinding ? null : response.currentUser;
    } catch (_error) {
      this.session.clearAuthSession();
      return null;
    }
  }

  async sendSms(mobile: string, agreed: boolean): Promise<void> {
    this.validateAgreement(agreed);
    if (!/^1[3-9]\d{9}$/.test(mobile)) throw new Error('请输入有效的大陆手机号');
    await this.repo.sendSms(mobile);
  }

  async smsLogin(mobile: string, code: string, agreed: boolean, bindingToken?: string): Promise<void> {
    this.validateAgreement(agreed);
    if (!/^1[3-9]\d{9}$/.test(mobile) || !/^\d{6}$/.test(code)) throw new Error('请输入手机号和6位验证码');
    this.acceptLogin(await this.repo.smsLogin(mobile, code, bindingToken));
  }

  private acceptLogin(response: LoginResponse): void {
    if (response.requiresMobileBinding) return;
    if (!response.token || !response.currentUser) throw new Error('登录响应无效');
    this.session.setToken(response.token);
    this.session.setCurrentUser(response.currentUser);
    navigateToPostLoginTab(response.currentUser.userType);
  }

  private async performWechatLogin(): Promise<LoginResponse> {
    const loginForm = await this.createWechatLoginForm();
    try {
      const loginResponse = await this.repo.login(loginForm);
      this.acceptLogin(loginResponse);
      return loginResponse;
    } catch (error) {
      throw new Error(getErrorMessage(error, '登录失败，请重试'));
    }
  }

  async logout(): Promise<void> {
    await this.repo.logout();
    this.session.clear();
    redirectToLoginPage();
  }
}

export const authService = new AuthService(new AuthRepository(), sessionService);
