<template><view class="detail-section"><text class="section-title">{{ title }}</text><slot /></view></template>
<script setup lang="ts">defineProps<{ title: string }>();</script>
<style scoped lang="scss">
.detail-section { padding: 32rpx; background: #fff; margin-bottom: 32rpx; border-radius: 20rpx 20rpx 0 0; }
.section-title { display: block; font-size: 32rpx; line-height: 46rpx; font-weight: 500; color: #0a0a0a; margin-bottom: 24rpx; }
</style>
