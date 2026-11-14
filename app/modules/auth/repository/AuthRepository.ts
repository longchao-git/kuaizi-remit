import request from '@/request/http';
import type { CurrentUser } from '@/modules/profile/domain/CurrentUser';
import type { LoginForm } from '../domain/LoginForm';
import type { LoginResponse } from '../domain/LoginResponse';

export class AuthRepository {
  private readonly basePath = 'app/auth';

  async login(data: LoginForm): Promise<LoginResponse> {
    return await request.post<LoginResponse>(`${this.basePath}/wechat-login`, data);
  }

  async logout(): Promise<boolean> {
    return await request.post<boolean>(`${this.basePath}/logout`);
  }

  async loginLocal(email: string, password: string): Promise<{ token: string; member: CurrentUser }> {
    return await request.post(`${this.basePath}/local-login`, { email, password }, { handleUnauthorized: false });
  }

  async current(): Promise<CurrentUser> {
    return await request.get(`${this.basePath}/current`, {}, { handleUnauthorized: false });
  }

  async logoutLocal(): Promise<boolean> {
    return await request.post(`${this.basePath}/logout`, {}, { handleUnauthorized: false });
  }

  async sendRemitSms(mobile: string): Promise<boolean> {
    return await request.post(this.basePath + '/sms-code', { mobile }, { handleUnauthorized: false });
  }

  async loginRemitSms(mobile: string, code: string): Promise<{ token: string; member: CurrentUser }> {
    return await request.post(this.basePath + '/sms-login', { mobile, code }, { handleUnauthorized: false });
  }

  async sendSms(mobile: string): Promise<boolean> {
    return await request.post<boolean>(`${this.basePath}/sms-code`, { mobile });
  }

  async smsLogin(mobile: string, code: string, bindingToken?: string): Promise<LoginResponse> {
    return await request.post<LoginResponse>(`${this.basePath}/${bindingToken ? 'wechat-bind-login' : 'sms-login'}`,
      bindingToken ? { mobile, code, bindingToken, confirmed: true } : { mobile, code });
  }
}
