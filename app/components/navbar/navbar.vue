<template>
	<view
		class="navbar"
		:style="{
			'--status-bar-height': `${statusBarHeight}px`,
			'--navbar-background': background,
			'--navbar-title-color': titleColor
		}"
	>
		<view v-if="placeholder" class="navbar-placeholder"></view>
		<view class="navbar-content" :class="{ fixed }">
			<view class="navbar-shell">
				<view class="navbar-side navbar-side-left">
					<slot name="left">
						<view v-if="showBack" class="navbar-back-btn" @click="handleClickOperation(0)">
							<image class="navbar-back-icon" :src="backIconSrc" mode="aspectFit"></image>
						</view>
					</slot>
				</view>
				<view class="navbar-title"><slot name="center">{{ title }}</slot></view>
				<view class="navbar-side navbar-side-right">
					<view v-if="showRight" class="navbar-right-btn" @click="handleClickOperation(1, 'clickRight')">
						<slot name="right"></slot>
					</view>
					<slot v-else name="right"></slot>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
	defineProps<{
		title?: string;
		showBack?: boolean;
		showRight?: boolean;
		backIconState?: number | string;
		background?: string;
		titleColor?: string;
		placeholder?: boolean;
		fixed?: boolean;
		interceptBack?: boolean;
	}>(),
	{
		title: '',
		showBack: false,
		showRight: false,
		backIconState: 1,
		background: 'transparent',
		titleColor: '#1f2a3d',
		placeholder: true,
		fixed: true
	}
);

const emit = defineEmits<{
	(event: 'clickRight'): void;
	(event: 'clickBack'): void;
}>();

const statusBarHeight = uni.getSystemInfoSync().statusBarHeight || 0;

const backIconSrc = computed(() => {
	return Number(props.backIconState) === 2 ? '/static/iconJiantouLeft.png' : '/static/iconLineIcon.png';
});

const handleClickOperation = (type: number, emitEvent?: 'clickRight') => {
	if (type === 0) {
		if (props.interceptBack) {
			emit('clickBack');
			return;
		}
		if (getCurrentPages().length > 1) {
			uni.navigateBack();
		} else {
			uni.switchTab({
				url: '/modules/home/pages/HomePage'
			});
		}
		return;
	}

	if (type === 1 && emitEvent) {
		emit(emitEvent);
	}
};
</script>

<style scoped lang="scss">
.navbar-placeholder {
	height: calc(var(--status-bar-height) + 88rpx);
}

.navbar-content {
	position: relative;
	top: 0;
	left: 0;
	right: 0;
	padding: var(--status-bar-height) 24rpx 0;
	background: var(--navbar-background);
	z-index: 20;
	box-sizing: border-box;
}

.navbar-content.fixed {
	position: fixed;
}

.navbar-shell {
	height: 88rpx;
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 0 8rpx;
	box-sizing: border-box;
}

.navbar-side {
	width: 120rpx;
	height: 100%;
	display: flex;
	align-items: center;
}

.navbar-side-right {
	justify-content: flex-end;
}

.navbar-title {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: 1;
	text-align: center;
	font-size: 32rpx;
	font-weight: 600;
	line-height: 44rpx;
	color: var(--navbar-title-color);
}

.navbar-back-btn,
.navbar-right-btn {
	display: flex;
	align-items: center;
	justify-content: flex-start;
	width: 40rpx;
	height: 40rpx;
	flex-shrink: 0;
}

.navbar-back-icon {
	width: 40rpx;
	height: 40rpx;
	display: block;
}
</style>
