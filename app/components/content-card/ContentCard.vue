<template>
	<view class="content-card" :class="{ 'content-card--landscape': landscape }" @click="emit('click')">
		<image
			v-if="image"
			class="content-cover-img"
			:src="image"
			mode="aspectFill"
		/>
		<view v-else class="content-cover-placeholder">
			<text class="content-cover-text">{{ firstChar }}</text>
		</view>
		<view class="content-info">
			<view>
				<view class="content-title">{{ title }}</view>
				<text class="content-desc">{{ description }}</text>
			</view>

			<view v-if="$slots.footer" class="content-bottom"><slot name="footer" /></view>
		</view>
	</view>
</template>

<script setup lang="ts">
import { computed } from 'vue';
const props = withDefaults(defineProps<{
	title: string;
	description?: string;
	image?: string;
	landscape?: boolean;
}>(), { description: '', image: '', landscape: false });
const emit = defineEmits<{ (event: 'click'): void }>();
const firstChar = computed(() => props.title.charAt(0));
</script>

<style scoped lang="scss">
.content-card {
	display: flex;
	gap: 24rpx;
	padding: 0;
	background: transparent;
	border-radius: 16rpx;
	margin-bottom: 32rpx;
	box-shadow: none;
}

.content-cover-img {
	width: 200rpx;
	height: 200rpx;
	border-radius: 16rpx;
	flex-shrink: 0;
}

.content-card--landscape .content-cover-img,
.content-card--landscape .content-cover-placeholder {
	width: 280rpx;
	height: 158rpx;
}

.content-card--landscape .content-desc {
	margin-top: 8rpx;
	-webkit-line-clamp: 1;
	line-clamp: 1;
}

.content-cover-placeholder {
	width: 200rpx;
	height: 200rpx;
	border-radius: 16rpx;
	background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
	display: flex;
	align-items: center;
	justify-content: center;
	flex-shrink: 0;
}

.content-cover-text {
	font-size: 42rpx;
	font-weight: 700;
	color: rgba(255, 255, 255, 0.8);
}

.content-info {
	flex: 1;
	display: flex;
	flex-direction: column;
	justify-content: space-between;
	min-width: 0;
}

.content-title {
	font-size: 28rpx;
	font-weight: 500;
	line-height: 34rpx;
	color: #101112;
	display: -webkit-box;
	line-clamp: 2;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	overflow: hidden;
}

.content-desc {
	margin-top: 16rpx;
	font-size: 24rpx;
	line-height: 32rpx;
	color: #87898e;
	display: -webkit-box;
	line-clamp: 2;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: normal;
}

.content-bottom {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
}

</style>
