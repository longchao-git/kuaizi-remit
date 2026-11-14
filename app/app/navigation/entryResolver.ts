import type { UserType } from '@/modules/auth/domain/UserType';

export function resolvePostLoginTabPath(_userType?: UserType): string {
  return '/modules/profile/pages/MyPage';
}

export function navigateToPostLoginTab(userType?: UserType): void {
  uni.switchTab({
    url: resolvePostLoginTabPath(userType)
  });
}

export function redirectToLoginPage(): void {
  const pages = getCurrentPages();
  const currentPage = pages[pages.length - 1];

  if (currentPage && currentPage.route === 'subPackages/login/pages/LoginPage') {
    return;
  }

  uni.reLaunch({
    url: '/subPackages/login/pages/LoginPage'
  });
}
