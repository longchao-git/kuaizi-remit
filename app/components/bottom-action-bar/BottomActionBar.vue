<template>
  <view class="action-placeholder" />
  <view class="bottom-action-bar"><slot name="leading" /><button class="action-button" :disabled="disabled || loading" @click="$emit('action')">{{ loading ? '处理中…' : label }}</button></view>
</template>
<script setup lang="ts">
withDefaults(defineProps<{ label?: string; disabled?: boolean; loading?: boolean }>(), { label: '保存', disabled: false, loading: false });
defineEmits<{ (e: 'action'): void }>();
</script>
<style scoped lang="scss">
.action-placeholder { height: calc(132rpx + env(safe-area-inset-bottom)); }
.bottom-action-bar { position: fixed; left: 0; right: 0; bottom: 0; display: flex; align-items: center; gap: 24rpx; padding: 24rpx 32rpx calc(24rpx + env(safe-area-inset-bottom)); background: #fff; box-sizing: border-box; z-index: 10; }
.action-button { flex: 1; margin: 0; padding: 0 24rpx; height: 84rpx; line-height: 84rpx; background: linear-gradient(92deg,#ff9e49 .38%,#ff3e24 99.88%); border-radius: 100rpx; color: #fff; font-size: 28rpx; font-weight: 500; }
.action-button::after { border: 0; }
.action-button[disabled] { color: #fff; opacity: .5; }
</style>
