import type { CurrentUser } from '@/modules/profile/domain/CurrentUser';

const STORAGE_KEY = {
  TOKEN: 'token',
  USER_INFO: 'userInfo',
  LOGIN_USER_INFO: 'loginUserInfo'
} as const;

function parseUser(rawValue: unknown): CurrentUser | null {
  if (!rawValue) {
    return null;
  }

  if (typeof rawValue === 'string') {
    try {
      return JSON.parse(rawValue) as CurrentUser;
    } catch (_error) {
      return null;
    }
  }

  return rawValue as CurrentUser;
}

export function getToken(): string {
  return uni.getStorageSync(STORAGE_KEY.TOKEN) || '';
}

export function setToken(token: string): void {
  uni.setStorageSync(STORAGE_KEY.TOKEN, token);
}

export function getCurrentUser(): CurrentUser | null {
  return parseUser(uni.getStorageSync(STORAGE_KEY.USER_INFO));
}

export function getLastLoginUser(): CurrentUser | null {
  return parseUser(uni.getStorageSync(STORAGE_KEY.LOGIN_USER_INFO));
}

export function setCurrentUser(user: CurrentUser): void {
  uni.setStorageSync(STORAGE_KEY.USER_INFO, user);
  uni.setStorageSync(STORAGE_KEY.LOGIN_USER_INFO, user);
}

export function clearAuthSession(): void {
  uni.removeStorageSync(STORAGE_KEY.TOKEN);
  uni.removeStorageSync(STORAGE_KEY.USER_INFO);
}

export function clearSessionStorage(): void {
  clearAuthSession();
  uni.removeStorageSync(STORAGE_KEY.LOGIN_USER_INFO);
}
