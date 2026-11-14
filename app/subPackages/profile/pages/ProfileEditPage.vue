<template>
	<view class="container">
		<navbar title="资料修改" showBack background="transparent"></navbar>

		<view class="content-card">
			<view class="avatar-section" @click="chooseAvatar">
				<view class="avatar-wrap">
					<image class="avatar-image" :src="form.headImg || '/static/logo.png'" mode="aspectFill"></image>
					<image class="avatar-camera" src="/static/iconRight.png" mode="aspectFit"></image>
				</view>
			</view>

			<view class="form-row">
				<view class="label">昵称</view>
				<input v-model="form.userName" :disabled="loading || saving || uploading" class="value-input" maxlength="64" placeholder="请输入昵称" />
			</view>

			<view class="form-row">
				<view class="label">性别</view>
				<view class="option-group">
					<view class="option-item" @click="selectGender('WOMAN')">
						<view class="option-radio" :class="{ active: form.gender === 'WOMAN' }"></view>
						<text class="option-text">女</text>
					</view>
					<view class="option-item" @click="selectGender('MAN')">
						<view class="option-radio" :class="{ active: form.gender === 'MAN' }"></view>
						<text class="option-text">男</text>
					</view>
				</view>
			</view>

			<view class="form-row intro-row">
				<view class="label">个人简介</view>
				<textarea
					v-model="form.profileBio"
					:disabled="loading || saving || uploading"
					class="intro-input"
					maxlength="255"
					auto-height
					placeholder="请输入个人简介"
				></textarea>
			</view>
		</view>

		<view class="submit-btn" :class="{ disabled: loading || saving || uploading }" @click="submit">
			{{ loading ? '加载中...' : saving ? '保存中...' : uploading ? '上传中...' : '确定' }}
		</view>
	</view>
</template>

<script setup lang="ts">
import { onLoad } from '@dcloudio/uni-app';
import { useProfileEditPage } from '@/subPackages/profile/application/useProfileEditPage';

const { form, loading, saving, uploading, initPage, chooseAvatar, submit } = useProfileEditPage();
function selectGender(value: 'MAN' | 'WOMAN') {
	if (!loading.value && !saving.value && !uploading.value) form.gender = value;
}

onLoad(() => {
	void initPage();
});
</script>

<style scoped lang="scss">
@import '@/uni.scss';
.container {
	min-height: 100vh;
	background: linear-gradient(180deg, $app-page-bg 0rpx, $app-page-bg-deep 100%);
	padding: 0 32rpx 144rpx;
	box-sizing: border-box;
}

.content-card {
	margin-top: 24rpx;
	padding: 32rpx;
	background: $app-card-bg;
	border-radius: 32rpx;
	border: 1rpx solid $app-card-border;
	box-shadow: $app-card-shadow;
}

.avatar-section {
	display: flex;
	justify-content: center;
	padding-bottom: 32rpx;
}

.avatar-wrap {
	position: relative;
	width: 160rpx;
	height: 160rpx;
	border-radius: 80rpx;
	overflow: hidden;
}

.avatar-image {
	width: 100%;
	height: 100%;
	background: $app-primary-pale;
}

.avatar-camera {
	position: absolute;
	right: 16rpx;
	bottom: 16rpx;
	width: 24rpx;
	height: 24rpx;
}

.form-row {
	padding: 28rpx 0;
	border-bottom: 1rpx solid $app-line-color;
}

.form-row:last-child {
	border-bottom: none;
	padding-bottom: 0;
}

.label {
	font-size: 28rpx;
	font-weight: 600;
	color: $app-article-title;
}

.value-input,
.intro-input {
	width: 100%;
	margin-top: 16rpx;
	font-size: 28rpx;
	color: $uni-color-paragraph;
}

.option-group {
	margin-top: 16rpx;
	display: flex;
	gap: 32rpx;
}

.option-item {
	display: inline-flex;
	align-items: center;
}

.option-radio {
	width: 32rpx;
	height: 32rpx;
	border-radius: 16rpx;
	border: 4rpx solid #d0d4db;
	margin-right: 12rpx;
	box-sizing: border-box;
}

.option-radio.active {
	border-color: $app-primary;
	background: radial-gradient(circle, $app-primary 0 48%, transparent 52% 100%);
}

.option-text {
	font-size: 28rpx;
	color: $app-text-main;
}

.intro-row {
	padding-bottom: 0;
}

.intro-input {
	min-height: 176rpx;
	line-height: 40rpx;
}

.submit-btn {
	margin-top: 32rpx;
	height: 96rpx;
	border-radius: 48rpx;
	background: linear-gradient(135deg, $app-primary, $app-primary-dark);
	font-size: 32rpx;
	font-weight: 600;
	color: #ffffff;
	display: flex;
	align-items: center;
	justify-content: center;
	box-shadow: 0 16rpx 32rpx rgba(21, 91, 212, 0.18);
}

.submit-btn.disabled {
	opacity: 0.65;
}
</style>
