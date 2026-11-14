import { reactive, ref } from 'vue';
import type { ProfileForm } from '@/modules/profile/domain/ProfileForm';
import { sessionService } from '@/shared/session/application/SessionService';
import { profileService } from '@/modules/profile/application/ProfileService';
import { getErrorMessage } from '@/shared/utils/error';

export const useProfileEditPage = () => {
  const loading = ref(false);
  const saving = ref(false);
  const uploading = ref(false);
  const form = reactive<ProfileForm>({ userName: '', headImg: '', gender: null, profileBio: '' });
  const applyUser = (user: NonNullable<ReturnType<typeof sessionService.getCurrentUser>>) => {
    form.userName = user.userName || '';
    form.headImg = user.headImg || '';
    form.gender = user.gender ?? null;
    form.profileBio = user.profileBio || '';
  };

  const initPage = async () => {
    loading.value = true;
    try {
      if (!(await sessionService.checkLogin(1))) return;
      const stored = sessionService.getCurrentUser();
      if (stored) applyUser(stored);
      applyUser(await profileService.loadCurrentUser());
    } catch (error) {
      uni.showToast({ title: getErrorMessage(error, '资料加载失败，请重试'), icon: 'none' });
    } finally {
      loading.value = false;
    }
  };

  const chooseAvatar = async () => {
    if (loading.value || saving.value || uploading.value) return;
    uploading.value = true;
    try {
      if (!(await sessionService.checkLogin(1))) return;
      const chosen = await new Promise<UniApp.ChooseImageSuccessCallbackResult>((resolve, reject) => {
        uni.chooseImage({ count: 1, sizeType: ['compressed'], sourceType: ['album', 'camera'], success: resolve, fail: reject });
      });
      const file = (chosen.tempFiles as { path: string; size: number; type?: string }[])[0];
      const filePath = chosen.tempFilePaths[0];
      if (!filePath) return;
      if (!file || file.size <= 0 || file.size > 5 * 1024 * 1024) throw new Error('头像应为不超过5MiB的图片');
      const info = await new Promise<UniApp.GetImageInfoSuccessData>((resolve, reject) => {
        uni.getImageInfo({ src: filePath, success: resolve, fail: reject });
      });
      const imageType = (info.type ?? file.type)?.toLowerCase();
      if (!imageType || !['png', 'jpeg', 'jpg', 'image/png', 'image/jpeg'].includes(imageType)) throw new Error('请选择PNG或JPEG图片');
      form.headImg = await profileService.uploadAvatar(filePath);
      uni.showToast({ title: '头像上传成功', icon: 'success' });
    } catch (error) {
      if (typeof error === 'object' && error !== null && 'errMsg' in error && String(error.errMsg).includes('cancel')) return;
      uni.showToast({ title: getErrorMessage(error, '头像上传失败'), icon: 'none' });
    } finally {
      uploading.value = false;
    }
  };

  const submit = async () => {
    if (loading.value || saving.value || uploading.value) return;
    if (!form.userName.trim()) { uni.showToast({ title: '请输入昵称', icon: 'none' }); return; }
    saving.value = true;
    try {
      if (!(await sessionService.checkLogin(1))) return;
      await profileService.updateCurrentUser({ ...form, userName: form.userName.trim(), profileBio: form.profileBio.trim() });
      uni.showToast({ title: '保存成功', icon: 'success' });
      // 保持提交锁直至返回，避免成功提示期间再次提交。
      await new Promise(resolve => setTimeout(resolve, 300));
      await new Promise<void>((resolve, reject) => uni.navigateBack({ success: () => resolve(), fail: reject }))
        .catch(() => uni.showToast({ title: '资料已保存，返回失败，请手动返回', icon: 'none' }));
    } catch (error) {
      uni.showToast({ title: getErrorMessage(error, '保存失败'), icon: 'none' });
    } finally {
      saving.value = false;
    }
  };
  return { form, loading, saving, uploading, initPage, chooseAvatar, submit };
};
