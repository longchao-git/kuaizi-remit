<template>
	<view class="menu-card">
		<view v-for="item in items" :key="item.key" class="menu-item" @click="emit('select', item.key)">
			<text class="menu-label">{{ item.label }}</text>
			<image class="menu-arrow" src="/static/icon_jiantou_c.png" mode="aspectFit" />
		</view>
	</view>
</template>

<script setup lang="ts">
defineProps<{ items: { key: string; label: string }[] }>();
const emit = defineEmits<{ (event: 'select', key: string): void }>();
</script>

<style scoped lang="scss">
	.menu-card {
		margin: 24rpx 32rpx 0;
		background: #ffffff;
		border-radius: 16rpx;
		box-shadow: 0 10rpx 28rpx rgba(25, 26, 29, 0.06);
		overflow: hidden;
		position: relative;
		z-index: 2;
	}

	.menu-item {
		display: flex;
		align-items: center;
		justify-content: space-between;
		padding: 32rpx;
	}



	.menu-label {
		font-size: 28rpx;
		color: #101112;
		font-weight: 400;
		line-height: 36rpx;
	}

	.menu-arrow {
		width: 32rpx;
		height: 32rpx;
	}


.menu-label { min-width: 0; overflow-wrap: anywhere; }
.menu-arrow { flex-shrink: 0; margin-left: 16rpx; }
</style>
