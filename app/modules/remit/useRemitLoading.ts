import { onScopeDispose, watch } from 'vue';

const visibleRequests = new Set<symbol>();

/** 只在可见的前台请求中显示 uni-app 的全局加载提示。 */
export function useRemitLoading(isVisible: () => boolean) {
  const owner = Symbol();

  function release() {
    if (visibleRequests.delete(owner) && visibleRequests.size === 0) {
      uni.hideLoading();
    }
  }

  watch(isVisible, (visible) => {
    if (!visible) {
      release();
      return;
    }
    visibleRequests.add(owner);
    if (visibleRequests.size === 1) {
      uni.showLoading({ title: '加载中' });
    }
  }, { flush: 'sync', immediate: true });
  onScopeDispose(release);
}
