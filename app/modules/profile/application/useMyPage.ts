import { computed, reactive, ref } from 'vue';
import type { CurrentUser } from '../domain/CurrentUser';
import { sessionService } from '@/shared/session/application/SessionService';
import { authService } from '@/modules/auth/application/AuthService';
import { profileService } from './ProfileService';
import { getErrorMessage } from '@/shared/utils/error';

export const useMyPage = () => {
  const userInfo = reactive<CurrentUser>(profileService.getEmptyUser());
  const loggingOut = ref(false);

  const isLoggedIn = computed(() => sessionService.isLoggedIn());
  const displayName = computed(() => userInfo.userName || '未登录用户');
  const userTypeText = computed(() => profileService.getUserTypeText(userInfo.userType));
  const displayBio = computed(() => userInfo.profileBio || '这里保留最基础的个人简介占位内容。');

  const syncUserInfo = () => {
    Object.assign(userInfo, profileService.getEmptyUser(), sessionService.getCurrentUser() || {});
  };

  const initPage = async () => {
    syncUserInfo();
    if (!(await sessionService.checkLogin())) {
      return;
    }

    try {
      const currentUser = await profileService.loadCurrentUser();
      Object.assign(userInfo, profileService.getEmptyUser(), currentUser);
    } catch (error) {
      syncUserInfo();
      uni.showToast({ title: getErrorMessage(error, '资料刷新失败，请重试'), icon: 'none' });
    }
  };

  const openEditProfile = async () => {
    if (!(await sessionService.checkLogin(1))) {
      return;
    }

    uni.navigateTo({ url: '/subPackages/profile/pages/ProfileEditPage' });
  };

  const logout = async () => {
    if (loggingOut.value) return;
    loggingOut.value = true;
    try { await authService.logout(); }
    catch (error) { uni.showToast({ title: getErrorMessage(error, '退出失败，请重试'), icon: 'none' }); }
    finally { loggingOut.value = false; }
  };

  return {
    userInfo,
    isLoggedIn,
    displayName,
    userTypeText,
    displayBio,
    initPage,
    openEditProfile,
    logout
  };
};
