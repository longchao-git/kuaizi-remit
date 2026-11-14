<template>
	<view class="content relative">
		<view class="page-mask"></view>

		<navbar v-if="showBack" showBack background="transparent"></navbar>
		<navbar v-else title="登录" background="transparent"></navbar>

		<view class="page-shell">
			<view class="login-card">
				<image src="/static/logo.png" class="logo-image" mode="aspectFill"></image>

				<view class="brand-title">应用名称</view>
				<view class="login-desc">{{ bindingToken ? '验证手机号后确认关联微信，沿用已有会员资料。' : '使用手机号登录；微信小程序也可使用微信登录。' }}</view>

				<view v-if="smsVisible" class="sms-fields">
					<input v-model="mobile" type="number" maxlength="11" placeholder="请输入手机号" aria-label="手机号" />
					<view class="sms-code-row">
						<input v-model="code" type="number" maxlength="6" placeholder="6位验证码" aria-label="验证码" />
						<button size="mini" :disabled="sending || remaining > 0" @click="sendCode">{{ remaining > 0 ? `${remaining}s` : '获取验证码' }}</button>
					</view>
				</view>

				<view class="login-btn" :class="{ disabled: isLogging }" @click="handleLogin">
					{{ isLogging ? '登录中...' : bindingToken ? '确认关联并登录' : smsVisible ? '手机号登录' : '微信授权登录' }}
				</view>

				<!-- #ifdef MP-WEIXIN -->
				<view v-if="bindingToken" class="debug-tip" @click="bindingToken = ''; code = ''; useSms = false">关联凭证失效？重新微信授权</view>
				<view v-if="!bindingToken" class="debug-tip" @click="useSms = !useSms">{{ useSms ? '切换微信登录' : '切换手机号登录' }}</view>
				<!-- #endif -->

				<view class="agreement-row">
					<image
						@click="toggleAgreement"
						:src="isAgreed ? '/static/agreement-on.png' : '/static/agreement-off.png'"
						mode="aspectFill"
						class="agreement-icon"
					></image>
					<view class="agreement-text agreement-highlight">我已阅读并同意</view>
					<view class="agreement-text agreement-link" @click.stop="showLegalPlaceholder('用户协议')">《用户协议》</view>
					<text class="agreement-text">及</text>
					<view class="agreement-text agreement-link" @click.stop="showLegalPlaceholder('隐私条款')">《隐私条款》</view>
				</view>
			</view>
		</view>
	</view>
</template>

<script setup lang="ts">
import { onShow } from '@dcloudio/uni-app';
import { useLoginPage } from '@/subPackages/login/application/useLoginPage';

const { isAgreed, isLogging, showBack, toggleAgreement, showLegalPlaceholder, handleLogin, initPage,
  mobile, code, bindingToken, useSms, smsVisible, sending, remaining, sendCode } = useLoginPage();

onShow(() => {
	void initPage();
});
</script>

<style lang="scss" scoped>
@import '@/uni.scss';
page {
	background: #ffffff;
}

.content {
	min-height: 100vh;
	overflow: hidden;
	position: relative;
}

.page-mask {
	position: absolute;
	inset: 0;
	z-index: 1;
	background: linear-gradient(180deg, $app-page-bg 0%, $app-page-bg-deep 100%);
}

.page-shell {
	position: relative;
	z-index: 2;
	min-height: calc(100vh - env(safe-area-inset-bottom));
	display: flex;
	justify-content: center;
	padding: 0 32rpx calc(env(safe-area-inset-bottom) + 32rpx);
	box-sizing: border-box;
}

.login-card {
	width: 100%;
	border-radius: 32rpx;
	margin-top: 120px;
	backdrop-filter: blur(16rpx);
	display: flex;
	flex-direction: column;
	align-items: center;
}

.logo-image {
	width: 208rpx;
	height: 208rpx;
}

.brand-title {
	margin-top: 48rpx;
	font-size: 44rpx;
	font-weight: 700;
	line-height: 56rpx;
	color: $app-primary;
}

.login-desc {
	width: 100%;
	margin-top: 24rpx;
	font-size: 28rpx;
	line-height: 44rpx;
	text-align: center;
	color: $app-text-secondary;
}

.login-btn {
	width: 100%;
	height: 96rpx;
	margin-top: 96rpx;
	border-radius: 48rpx;
	background: linear-gradient(135deg, $app-primary, $app-primary-dark);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 32rpx;
	font-weight: 500;
	color: #ffffff;
	box-shadow: 0 16rpx 32rpx rgba(21, 91, 212, 0.18);
}

.login-btn.disabled {
	opacity: 0.7;
}

.sms-fields { width: 100%; margin-top: 32rpx; }
.sms-fields input { height: 88rpx; padding: 0 24rpx; background: #ffffff; border-radius: 16rpx; font-size: 28rpx; color: $app-text-main; }
.sms-code-row { display: flex; align-items: center; gap: 16rpx; margin-top: 16rpx; }
.sms-code-row input { flex: 1; min-width: 0; }
.sms-code-row button { flex-shrink: 0; color: $app-primary; }
.sms-fields + .login-btn { margin-top: 32rpx; }

.debug-tip {
	width: 100%;
	margin-top: 24rpx;
	font-size: 24rpx;
	line-height: 40rpx;
	text-align: center;
	color: $app-text-muted;
}

.agreement-row {
	margin-top: 48rpx;
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: center;
	column-gap: 8rpx;
	row-gap: 8rpx;
}

.agreement-icon {
	width: 32rpx;
	height: 32rpx;
	margin-right: 12rpx;
}

.agreement-text {
	font-size: 24rpx;
	line-height: 32rpx;
	color: $app-text-main;
}

.agreement-highlight {
	color: $app-primary;
}

.agreement-link {
	color: $app-primary;
	text-decoration: underline;
}
</style>
