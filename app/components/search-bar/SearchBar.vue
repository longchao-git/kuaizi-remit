<template>
  <view class="search-bar">
    <input class="search-input" :value="modelValue" :placeholder="placeholder" confirm-type="search" :disabled="disabled" @input="$emit('update:modelValue', ($event as CustomEvent<{ value: string }>).detail.value)" @confirm="search" />
    <button v-if="modelValue" class="icon-button" aria-label="清除搜索" :disabled="disabled" @click="$emit('update:modelValue', '')">×</button>
    <button class="icon-button" aria-label="搜索" :disabled="disabled" @click="search"><image class="search-icon" src="/static/icon_sousuo.png" mode="aspectFit" /></button>
  </view>
</template>
<script setup lang="ts">
const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string; disabled?: boolean }>(), { placeholder: '请输入关键词', disabled: false });
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void; (e: 'search', value: string): void }>();
function search() { if (!props.disabled) emit('search', props.modelValue.trim()); }
</script>
<style scoped lang="scss">
.search-bar { display: flex; align-items: center; gap: 12rpx; margin: 32rpx 32rpx 0; padding: 0 24rpx; background: #f7f8fb; border-radius: 16rpx; height: 88rpx; }
.search-input { flex: 1; min-width: 0; font-size: 28rpx; color: $app-text-main; }
.icon-button { display: flex; align-items: center; justify-content: center; padding: 0; margin: 0; background: transparent; width: 56rpx; height: 64rpx; line-height: 64rpx; flex-shrink: 0; }
.icon-button::after { border: 0; }
.search-icon { width: 42rpx; height: 42rpx; }
</style>
