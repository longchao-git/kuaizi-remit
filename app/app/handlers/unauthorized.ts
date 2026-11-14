import { setUnauthorizedHandler } from '@/request/http';
import { redirectToLoginPage } from '@/app/navigation/entryResolver';
import { sessionService } from '@/shared/session/application/SessionService';

export function initializeUnauthorizedHandler(): void {
  setUnauthorizedHandler((error) => {
    sessionService.clearAuthSession();
    uni.showToast({
      title: error.message,
      icon: 'none'
    });
    redirectToLoginPage();
  });
}
