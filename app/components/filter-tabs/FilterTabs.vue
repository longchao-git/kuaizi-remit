<template>
  <scroll-view class="filter-tabs" :class="variant" scroll-x enable-flex :show-scrollbar="false">
    <view class="tab-list">
      <button v-for="item in items" :key="item.value" class="tab-item" :class="{ active: modelValue === item.value }" :disabled="item.disabled" :aria-label="item.label" @click="select(item)">
        <text class="tab-text">{{ item.label }}</text><view v-if="variant === 'underline'" class="tab-indicator" />
      </button>
    </view>
  </scroll-view>
</template>
<script setup lang="ts">
interface Tab { value: string; label: string; disabled?: boolean }
const props = withDefaults(defineProps<{ modelValue: string; items: Tab[]; variant?: 'pills' | 'underline' }>(), { variant: 'pills' });
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void; (e: 'change', value: string): void }>();
function select(item: Tab) { if (!item.disabled && item.value !== props.modelValue) { emit('update:modelValue', item.value); emit('change', item.value); } }
</script>
<style scoped lang="scss">
.filter-tabs { width: 100%; white-space: nowrap; }
.tab-list { display: flex; gap: 24rpx; padding: 24rpx 32rpx 0; box-sizing: border-box; }
.tab-item { margin: 0; flex-shrink: 0; padding: 0 20rpx; border-radius: 999rpx; background: #fff; border: 1rpx solid #BDBFC1; color: $app-text-secondary; font-size: 24rpx; line-height: 56rpx; }
.tab-item::after { border: 0; }
.tab-item.active { background: $app-primary-pale; border-color: rgba(254,111,58,.28); color: $app-primary; font-weight: 500; }
.tab-item[disabled] { opacity: .45; }
.underline .tab-list { justify-content: space-between; min-width: 100%; padding-bottom: 8rpx; }
.underline .tab-item { display: flex; flex-direction: column; align-items: center; gap: 16rpx; padding: 0 0 8rpx; background: transparent; border: 0; border-radius: 0; color: #2d323f; }
.underline .tab-text { font-size: 28rpx; line-height: 36rpx; }
.underline .active .tab-text { color: #0a0a0a; font-weight: 500; }
.tab-indicator { width: 36rpx; height: 8rpx; border-radius: 100rpx; background: #ff7138; opacity: 0; }
.active .tab-indicator { opacity: 1; }
</style>
