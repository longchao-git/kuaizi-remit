import { computed, reactive } from 'vue';
import type { CurrentUser } from '@/modules/profile/domain/CurrentUser';
import { sessionService } from '@/shared/session/application/SessionService';
import { profileService } from '@/modules/profile/application/ProfileService';

export const useHomePage = () => {
  const userInfo = reactive<CurrentUser>(profileService.getEmptyUser());

  const isLoggedIn = computed(() => sessionService.isLoggedIn());
  const displayName = computed(() => userInfo.userName || '未登录用户');
  const userTypeText = computed(() => profileService.getUserTypeText(userInfo.userType));

  const initPage = () => {
    Object.assign(userInfo, profileService.getEmptyUser(), sessionService.getCurrentUser() || {});
  };

  const openMyPage = () => {
    uni.switchTab({ url: '/modules/profile/pages/MyPage' });
  };

  return {
    isLoggedIn,
    displayName,
    userTypeText,
    initPage,
    openMyPage
  };
};
