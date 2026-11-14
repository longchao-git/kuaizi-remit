import type { CurrentUser } from '@/modules/profile/domain/CurrentUser';
import { ref } from 'vue';
import {
  clearAuthSession,
  clearSessionStorage,
  getCurrentUser as getStoredCurrentUser,
  getLastLoginUser as getStoredLastLoginUser,
  getToken as getStoredToken,
  setCurrentUser as saveCurrentUser,
  setToken as saveToken
} from '../storage';

export class SessionService {
  private readonly token = ref(getStoredToken());

  getToken(): string {
    return this.token.value;
  }

  isLoggedIn(): boolean {
    return !!this.getToken();
  }

  async checkLogin(type?: number): Promise<boolean> {
    if (this.getToken() && this.getCurrentUser()) {
      return true;
    }

    if (!type) {
      return false;
    }

    return await new Promise<boolean>((resolve) => {
      uni.showModal({
        title: '提示',
        content: '点击登录后即可体验更多功能！',
        cancelText: '暂不登录',
        confirmText: '立即登录',
        success: (res: UniApp.ShowModalRes) => {
          if (res.confirm) {
            uni.navigateTo({ url: '/subPackages/login/pages/LoginPage' });
          }
        },
        complete: () => {
          resolve(false);
        }
      });
    });
  }

  getCurrentUser(): CurrentUser | null {
    return getStoredCurrentUser();
  }

  hasLoginMemory(): boolean {
    return !!getStoredLastLoginUser();
  }

  setToken(token: string): void {
    saveToken(token);
    this.token.value = token;
  }

  setCurrentUser(user: CurrentUser): void {
    saveCurrentUser(user);
  }

  clearAuthSession(): void {
    clearAuthSession();
    this.token.value = '';
  }

  clear(): void {
    clearSessionStorage();
    this.token.value = '';
  }
}

export const sessionService = new SessionService();
