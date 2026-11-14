<template>
  <view>
    <view class="section-header"><text class="section-title">{{ label }}{{ required ? ' *' : '' }}</text><text v-if="description" class="section-desc">{{ description }}</text></view>
    <view class="text-area-wrap"><textarea class="text-input" :value="modelValue" :aria-label="label" :placeholder="placeholder" :maxlength="maxlength" :disabled="disabled" auto-height @input="$emit('update:modelValue', ($event as CustomEvent<{ value: string }>).detail.value)" /><text class="char-count"><text class="char-count-current">{{ modelValue.length }}</text>/{{ maxlength }}</text></view>
    <text v-if="error" class="field-error" role="alert">{{ error }}</text>
  </view>
</template>
<script setup lang="ts">
withDefaults(defineProps<{ modelValue: string; label: string; description?: string; placeholder?: string; maxlength?: number; required?: boolean; disabled?: boolean; error?: string }>(), { description: '', placeholder: '请输入内容', maxlength: 100, required: false, disabled: false, error: '' });
defineEmits<{ (e: 'update:modelValue', value: string): void }>();
</script>
<style scoped lang="scss">
.section-header { display: flex; flex-direction: column; gap: 10rpx; margin-bottom: 24rpx; }
.section-title { font-size: 32rpx; line-height: 44rpx; font-weight: 600; color: #101112; display: block; }
.section-desc { font-size: 24rpx; line-height: 38rpx; color: $app-text-secondary; display: block; }
.text-area-wrap { background: #fafbfd; border-radius: 20rpx; padding: 24rpx; min-height: 488rpx; box-sizing: border-box; display: flex; flex-direction: column; margin-bottom: 24rpx; }
.text-input { width: 100%; flex: 1; min-height: 400rpx; font-size: 28rpx; line-height: 36rpx; color: #101112; }
.char-count { align-self: flex-end; margin-top: 24rpx; font-size: 20rpx; line-height: 28rpx; color: $app-text-secondary; }
.char-count-current { color: #5b5f67; }
.field-error { display: block; margin: -8rpx 0 24rpx; color: #c93636; font-size: 24rpx; line-height: 36rpx; }
</style>
