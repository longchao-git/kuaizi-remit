<template>
	<view class="tabbar">
		<view class="tabbar-bg">
			<view class="tabbar-content">
				<view v-for="(item, index) in items" :key="item.pagePath" class="tabbar-item" @click="select(index)">
					<view class="tabbar-item-content" :class="{ 'tabbar-item-selected': selected === index }">
						<view class="tabbar-icon-wrapper">
							<image class="tabbar-icon" :src="selected === index ? item.selectedIconPath : item.iconPath" mode="aspectFit" />
						</view>
						<text class="tabbar-label" :class="{ active: selected === index }">{{ item.text }}</text>
					</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { appTabs } from '@/shared/navigation/tabs';
const props = withDefaults(defineProps<{
	selected: number;
	items?: { pagePath: string; iconPath: string; selectedIconPath: string; text: string }[];
}>(), { items: () => appTabs });
const navigating = ref(false);
function select(index: number) {
	if (index === props.selected || navigating.value) return;
	navigating.value = true;
	uni.switchTab({
		url: props.items[index].pagePath,
		fail: () => uni.showToast({ title: '页面切换失败', icon: 'none' }),
		complete: () => { navigating.value = false; }
	});
}
</script>

<style scoped lang="scss">
	.tabbar {
		height: 152rpx;
	}

	.tabbar-bg {
		height: 152rpx;
		position: fixed;
		bottom: 0;
		left: 0;
		right: 0;
		width: 100%;
		z-index: 99;
		background: transparent;
	}

	.tabbar-content {
		margin: 0 32rpx 32rpx 32rpx;
		padding: 0 24rpx;
		height: 116rpx;
		display: flex;
		z-index: 9;
		font-size: 20rpx;
		overflow: hidden;
		border-radius: 100px;
		border: 2rpx solid #fff;
		background: rgba(250, 250, 250, 0.4);
		box-shadow: 0 0 4rpx 0 rgba(255, 255, 255, 0.25) inset, 0 12rpx 40rpx 0 rgba(25, 26, 29, 0.10);
		backdrop-filter: blur(24rpx) saturate(160%);
		-webkit-backdrop-filter: blur(24rpx) saturate(160%);
	}

	.tabbar-item {
		flex: 1;
		text-align: center;
		display: flex;
		justify-content: center;
		align-items: center;
		flex-direction: column;
	}

	.tabbar-item-content {
		width: 100%;
		height: 96rpx;
		border-radius: 100rpx;

		text-align: center;
		display: flex;
		justify-content: center;
		align-items: center;
		flex-direction: column;
	}

	.tabbar-item-selected {
		background: rgba(238, 238, 238, 0.70);
		background-blend-mode: plus-darker;
		backdrop-filter: blur(24rpx) saturate(160%);
		-webkit-backdrop-filter: blur(24rpx) saturate(160%);
	}

	.tabbar-icon-wrapper {
		position: relative;
		display: inline-flex;
		margin-bottom: 4rpx;
	}

.tabbar, .tabbar-bg { padding-bottom: env(safe-area-inset-bottom); }
.tabbar-icon { width: 48rpx; height: 48rpx; }
.tabbar-label { font-size: 20rpx; line-height: 28rpx; color: #2D323F; }
.tabbar-label.active { color: #FF7138; }
</style>
