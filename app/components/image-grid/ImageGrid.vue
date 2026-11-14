<template>
  <view>
    <view class="image-grid">
      <view v-for="(url, index) in modelValue" :key="url" class="image-item">
        <image class="image-preview" :src="url" mode="aspectFill" @click="preview(index)" />
        <button v-if="!readonly" class="image-remove" :disabled="busy" :aria-label="'移除图片' + (index + 1)" @click="remove(index)"><image class="image-remove-icon" src="/static/iconCloseLine.png" mode="aspectFit" /></button>
      </view>
      <button v-if="!readonly && modelValue.length < limit" class="image-add" :disabled="busy" @click="choose"><image class="add-icon" src="/static/icon_tupian.png" mode="aspectFit" /><text class="add-text">{{ busy ? '处理中…' : '选择图片' }}</text></button>
    </view>
    <text class="image-hint">{{ modelValue.length }}/{{ limit }} 张 · 每张不超过 {{ maxSizeBytes / 1024 / 1024 }} MiB</text>
    <text v-if="error" class="image-error" role="alert">{{ error }}</text>
  </view>
</template>
<script setup lang="ts">
import { ref } from 'vue';
interface SelectedImage { path: string; size: number }
const props = withDefaults(defineProps<{ modelValue: string[]; uploadAdapter: (file: SelectedImage) => Promise<string>; limit?: number; maxSizeBytes?: number; readonly?: boolean }>(), { limit: 4, maxSizeBytes: 5 * 1024 * 1024, readonly: false });
const emit = defineEmits<{ (e: 'update:modelValue', value: string[]): void; (e: 'busy', value: boolean): void }>();
const busy = ref(false);
const error = ref('');
function remove(index: number) { if (!busy.value && !props.readonly) emit('update:modelValue', props.modelValue.filter((_, i) => i !== index)); }
function preview(index: number) { uni.previewImage({ urls: props.modelValue, current: index }); }
function choose() {
  if (busy.value || props.readonly || props.modelValue.length >= props.limit) return;
  busy.value = true; error.value = ''; emit('busy', true);
  uni.chooseImage({ count: props.limit - props.modelValue.length, sizeType: ['original'], success: async result => {
    const selected = result.tempFiles as SelectedImage[];
    const urls = [...props.modelValue];
    try {
      for (const file of selected.slice(0, props.limit - urls.length)) {
        if (file.size > props.maxSizeBytes) throw new Error('图片过大，请选择符合限制的图片');
        const url = await props.uploadAdapter(file);
        if (!url || typeof url !== 'string') throw new Error('上传未返回图片地址，请重试');
        urls.push(url); emit('update:modelValue', [...urls]);
      }
    } catch (cause) { error.value = cause instanceof Error ? cause.message : '图片处理失败，请重新选择'; }
    finally { busy.value = false; emit('busy', false); }
  }, fail: cause => { if (!cause.errMsg.includes('cancel')) error.value = '无法选择图片，请重试'; busy.value = false; emit('busy', false); } });
}
</script>
<style scoped lang="scss">
.image-grid { display: flex; gap: 16rpx; flex-wrap: wrap; }
.image-item, .image-add { width: 160rpx; height: 160rpx; position: relative; border-radius: 16rpx; overflow: hidden; flex-shrink: 0; }
.image-preview { width: 100%; height: 100%; border-radius: 16rpx; display: block; }
.image-remove { position: absolute; top: 0; right: 0; padding: 0; margin: 0; width: 40rpx; height: 40rpx; border-radius: 0 0 0 18rpx; background: rgba(0,0,0,.72); display: flex; align-items: center; justify-content: center; z-index: 2; }
.image-remove::after, .image-add::after { border: 0; }
.image-remove-icon { width: 18rpx; height: 18rpx; }
.image-add { margin: 0; padding: 0; background: #f6f7fb; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8rpx; }
.add-icon { width: 48rpx; height: 48rpx; }
.add-text { font-size: 20rpx; line-height: 28rpx; color: $app-text-muted; }
.image-hint, .image-error { display: block; margin-top: 16rpx; font-size: 24rpx; line-height: 36rpx; color: $app-text-secondary; }
.image-error { color: #c93636; }
</style>
