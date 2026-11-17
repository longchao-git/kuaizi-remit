<template>
  <web-view v-if="useBundledPrivacy" src="/static/privacy.html" />
  <view v-else class="agreement-content">
    <text v-if="loading">正在读取…</text>
    <template v-else-if="error">
      <text>{{ error }}</text>
      <button size="mini" @tap="load">重试</button>
      <button v-if="kind === 'privacy'" size="mini" @tap="showBundledPrivacy = true">查看内置隐私政策</button>
    </template>
    <text v-else-if="document" class="agreement-body" selectable>{{ document.body }}</text>
    <text v-else>{{ title }}尚未发布</text>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { onLoad } from '@dcloudio/uni-app';
import request from '@/request/http';

interface PublishedDocument {
  body: string;
}

const kind = ref<'terms' | 'privacy'>('privacy');
const document = ref<PublishedDocument | null>(null);
const loading = ref(true);
const error = ref('');
const showBundledPrivacy = ref(false);
const title = computed(() => kind.value === 'terms' ? '用户协议' : '隐私政策');
const useBundledPrivacy = computed(() => kind.value === 'privacy' && (
  showBundledPrivacy.value || (!loading.value && !error.value && !document.value)
));

async function load() {
  loading.value = true;
  error.value = '';
  try {
    const result = await request.get<{ document: PublishedDocument | null }>(
      'app/agreements/' + kind.value, {}, { handleUnauthorized: false },
    );
    document.value = result.document;
  } catch {
    error.value = '读取' + title.value + '失败，请重试';
  } finally {
    loading.value = false;
  }
}

onLoad((options) => {
  kind.value = options?.kind === 'terms' ? 'terms' : 'privacy';
  uni.setNavigationBarTitle({ title: title.value });
  void load();
});
</script>

<style scoped>
.agreement-content { display: flex; flex-direction: column; gap: 24rpx; padding: 36rpx; }
.agreement-body { font-size: 30rpx; line-height: 1.7; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
