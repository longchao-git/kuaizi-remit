<template>
	<view class="empty-state">
		<view class="empty-graphic">
			<image class="empty-image" :src="resolvedImage" mode="aspectFill"></image>
		</view>
		<view class="empty-title">{{ title }}</view>
		<view v-if="desc" class="empty-desc">{{ desc }}</view>
		<view v-if="buttonText" class="empty-action" @click="emit('action')">{{ buttonText }}</view>
	</view>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
	defineProps<{
		title?: string;
		desc?: string;
		buttonText?: string;
		variant?: 'default' | 'message' | 'network';
		image?: string;
	}>(),
	{
		title: '暂无内容',
		desc: '',
		buttonText: '',
		variant: 'default',
		image: ''
	}
);

const variantImageMap = {
	default: '/static/empty-state/empty-default.png',
	message: '/static/empty-state/empty-message.png',
	network: '/static/empty-state/empty-network.png'
} as const;
const resolvedImage = computed(() => props.image || variantImageMap[props.variant]);

const emit = defineEmits<{
	(event: 'action'): void;
}>();
</script>

<style scoped lang="scss">
.empty-state {
	padding: 120rpx 40rpx 96rpx;
	min-height: 420rpx;
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	text-align: center;
	box-sizing: border-box;
}

.empty-graphic {
	width: 260rpx;
	height: 260rpx;
	display: flex;
	align-items: center;
	justify-content: center;
}

.empty-image {
	width: 260rpx;
	height: 260rpx;
}

.empty-title {
	margin-top: 20rpx;
	font-size: 30rpx;
	line-height: 42rpx;
	font-weight: 500;
	color: #b7bcc7;
}

.empty-desc {
	margin-top: 8rpx;
	font-size: 24rpx;
	line-height: 36rpx;
	color: #c3c8d1;
}

.empty-action {
	margin-top: 32rpx;
	min-width: 216rpx;
	height: 84rpx;
	padding: 0 36rpx;
	border-radius: 999rpx;
	background: linear-gradient(92deg, #ff9e49 0.38%, #ff3e24 99.88%);
	font-size: 28rpx;
	font-weight: 500;
	color: #ffffff;
	display: flex;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
}
</style>
