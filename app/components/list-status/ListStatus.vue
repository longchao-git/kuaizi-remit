<template>
  <EmptyState v-if="state === 'empty'" :title="emptyText" />
  <view v-else class="list-status" :role="state === 'error' ? 'alert' : 'status'">
    <text>{{ message || labels[state] }}</text>
    <button v-if="state === 'error' || state === 'more'" class="status-action" @click="$emit('action')">{{ state === 'error' ? '重新加载' : '加载更多' }}</button>
  </view>
</template>
<script setup lang="ts">
import EmptyState from '@/components/empty-state/empty-state.vue';
withDefaults(defineProps<{ state: 'loading' | 'empty' | 'error' | 'more' | 'end'; message?: string; emptyText?: string }>(), { message: '', emptyText: '暂无内容' });
defineEmits<{ (e: 'action'): void }>();
const labels = { loading: '加载中…', error: '加载失败，请重试', more: '', end: '— 已经到底了 —' };
</script>
<style scoped lang="scss">
.list-status { text-align: center; padding: 40rpx 0; font-size: 26rpx; color: $app-text-muted; }
.status-action { padding: 0 24rpx; margin: 16rpx auto 0; width: max-content; background: transparent; color: $app-primary; font-size: 26rpx; }
.status-action::after { border: 0; }
</style>
