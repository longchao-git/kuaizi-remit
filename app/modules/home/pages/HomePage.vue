<template>
	<view class="container">
		<navbar title="首页" background="transparent"></navbar>

		<view class="hero-card">
			<view class="hero-badge">TEMPLATE APP</view>
			<view class="hero-title">客户端基础模板</view>
			<view class="hero-desc">
				当前页面只保留模板首页能力，用于承载导航入口、登录状态展示与后续业务扩展。
			</view>
		</view>

		<view class="panel">
			<view class="panel-header">
				<view class="panel-title">当前状态</view>
				<view class="panel-tag" :class="{ active: isLoggedIn }">{{ isLoggedIn ? '已登录' : '未登录' }}</view>
			</view>
			<view class="info-row">
				<text class="info-label">用户昵称</text>
				<text class="info-value">{{ displayName }}</text>
			</view>
			<view class="info-row">
				<text class="info-label">用户类型</text>
				<text class="info-value">{{ userTypeText }}</text>
			</view>
		</view>

		<view class="panel">
			<view class="panel-title">快捷入口</view>
			<view class="action-grid">
				<view class="action-card" @click="openMyPage">
					<view class="action-icon">M</view>
					<view class="action-name">个人中心</view>
					<view class="action-desc">查看资料与登录入口</view>
				</view>
				<view class="action-card" @click="openShowcase">
					<view class="action-name">组件示例</view>
					<view class="action-desc">原组件的样式与交互</view>
				</view>
			</view>
		</view>
		<tabbar :selected="0" />
	</view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app';
import { hideNativeTabBar } from '@/shared/navigation/tabs';
import { useHomePage } from '@/modules/home/application/useHomePage';

const { isLoggedIn, displayName, userTypeText, initPage, openMyPage } = useHomePage();

onShow(() => {
	hideNativeTabBar();
	initPage();
});
function openShowcase() {
	uni.navigateTo({ url: '/subPackages/showcase/pages/ComponentShowcasePage' });
}
</script>

<style scoped lang="scss">
@import '@/uni.scss';
.container {
	min-height: 100vh;
	padding: 0 32rpx;
	box-sizing: border-box;
	background: linear-gradient(180deg, $app-page-bg 0%, $app-page-bg-deep 100%);
}

.hero-card,
.panel {
	background: $app-card-bg;
	border: 1rpx solid $app-card-border;
	border-radius: 16rpx;
	box-shadow: $app-card-shadow;
}

.hero-card {
	padding: 32rpx;
}

.hero-badge {
	display: inline-flex;
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	background: $app-primary-soft;
	color: $app-primary;
	font-size: 24rpx;
	font-weight: 700;
	letter-spacing: 0;
}

.hero-title {
	margin-top: 24rpx;
	font-size: 48rpx;
	font-weight: 700;
	color: $app-text-main;
}

.hero-desc {
	margin-top: 16rpx;
	font-size: 28rpx;
	line-height: 40rpx;
	color: $app-text-secondary;
}

.panel {
	margin-top: 24rpx;
	padding: 32rpx;
}

.panel-header {
	display: flex;
	justify-content: space-between;
	align-items: center;
}

.panel-title {
	font-size: 32rpx;
	font-weight: 700;
	color: $app-text-main;
}

.panel-tag {
	padding: 8rpx 16rpx;
	border-radius: 999rpx;
	background: $app-primary-pale;
	color: $app-text-muted;
	font-size: 24rpx;
}

.panel-tag.active {
	background: $app-success-soft;
	color: $app-success-text;
}

.info-row {
	margin-top: 24rpx;
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 16rpx;
}

.info-label {
	font-size: 28rpx;
	color: $app-text-secondary;
}

.info-value {
	font-size: 28rpx;
	color: $app-text-main;
	font-weight: 600;
}

.action-grid {
	margin-top: 24rpx;
	display: grid;
	grid-template-columns: repeat(2, minmax(0, 1fr));
	gap: 16rpx;
}

.action-card {
	padding: 24rpx;
	border-radius: 16rpx;
	background: linear-gradient(180deg, #ffffff 0%, $app-primary-pale 100%);
}

.action-icon {
	width: 64rpx;
	height: 64rpx;
	border-radius: 20rpx;
	background: linear-gradient(135deg, $app-primary, $app-primary-dark);
	color: #ffffff;
	font-size: 32rpx;
	font-weight: 700;
	display: flex;
	align-items: center;
	justify-content: center;
}

.action-name {
	margin-top: 16rpx;
	font-size: 32rpx;
	font-weight: 700;
	color: $app-text-main;
}

.action-desc {
	margin-top: 8rpx;
	font-size: 24rpx;
	line-height: 36rpx;
	color: $app-text-muted;
}
</style>
