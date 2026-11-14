import { computed, onUnmounted, ref } from 'vue';
import { authService } from '@/modules/auth/application/AuthService';
import { sessionService } from '@/shared/session/application/SessionService';
import { navigateToPostLoginTab } from '@/app/navigation/entryResolver';

export const useLoginPage = () => {
  const isAgreed = ref(false);
  const isLogging = ref(false);
  const autoLoginTried = ref(false);
  const showBack = ref(false);
  const mobile = ref('');
  const code = ref('');
  const bindingToken = ref('');
  const useSms = ref(true);
  // #ifdef MP-WEIXIN
  useSms.value = false;
  // #endif
  const sending = ref(false);
  const remaining = ref(0);
  const smsVisible = computed(() => useSms.value || !!bindingToken.value);
  let timer: ReturnType<typeof setInterval> | undefined;
  onUnmounted(() => { if (timer) clearInterval(timer); });

  const sendCode = async () => {
    if (sending.value || remaining.value > 0) return;
    sending.value = true;
    try {
      await authService.sendSms(mobile.value, isAgreed.value);
      remaining.value = 60;
      timer = setInterval(() => {
        remaining.value -= 1;
        if (remaining.value <= 0) clearInterval(timer);
      }, 1000);
      uni.showToast({ title: '验证码已发送', icon: 'none' });
    } catch (error) {
      uni.showToast({ title: error instanceof Error ? error.message : '发送失败', icon: 'none' });
    } finally { sending.value = false; }
  };

  const toggleAgreement = () => {
    isAgreed.value = !isAgreed.value;
  };

  const showLegalPlaceholder = (documentName: '用户协议' | '隐私条款') => {
    uni.showModal({
      title: documentName,
      content: `${documentName}页面尚未接入，请在正式项目中替换此占位入口。`,
      showCancel: false
    });
  };

  const handleLogin = async () => {
    if (isLogging.value) {
      return;
    }

    isLogging.value = true;
    try {
      if (smsVisible.value) {
        if (bindingToken.value) {
          const result = await uni.showModal({ title: '确认关联账号', content: `将当前微信关联到 ${mobile.value} 的会员账号；已有资料保留，不合并其他会员。`, confirmText: '确认关联' });
          if (!result.confirm) return;
        }
        await authService.smsLogin(mobile.value, code.value, isAgreed.value, bindingToken.value || undefined);
      } else {
        const response = await authService.login(isAgreed.value);
        if (response.requiresMobileBinding) bindingToken.value = response.bindingToken;
      }
    } catch (error) {
      uni.showToast({
        title: error instanceof Error ? error.message : '登录失败，请重试',
        icon: 'none'
      });
    } finally {
      isLogging.value = false;
    }
  };

  const initPage = async () => {
    showBack.value = getCurrentPages().length > 1;

    if (isLogging.value) {
      return;
    }

    const currentUser = sessionService.getCurrentUser();
    if (sessionService.getToken() && currentUser) {
      navigateToPostLoginTab(currentUser.userType);
      return;
    }

    if (autoLoginTried.value || !sessionService.hasLoginMemory()) {
      return;
    }

    autoLoginTried.value = true;
    isLogging.value = true;
    try {
      const restoredUser = await authService.tryAutoLogin();
      if (!restoredUser) {
        return;
      }
      uni.showToast({
        title: '已自动恢复登录',
        icon: 'none'
      });
    } finally {
      isLogging.value = false;
    }
  };

  return {
    mobile, code, bindingToken, useSms, smsVisible, sending, remaining, sendCode,
    isAgreed,
    isLogging,
    showBack,
    toggleAgreement,
    showLegalPlaceholder,
    handleLogin,
    initPage
  };
};
