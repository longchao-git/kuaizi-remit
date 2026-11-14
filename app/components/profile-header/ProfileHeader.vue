<template>
	<view class="profile-row" @click="emit('edit')">
		<view class="profile-left">
			<text class="profile-name">{{ name }}</text>
			<view class="profile-bio">
				<text v-for="(line, index) in bio.split('\n')" :key="index" class="profile-bio-line" space="ensp">{{ line }}</text>
			</view>
			<slot name="badges" />
		</view>
		<view class="avatar-wrap"><image class="avatar-img" :src="avatar" mode="aspectFill" /></view>
	</view>
</template>

<script setup lang="ts">
defineProps<{ name: string; bio: string; avatar: string }>();
const emit = defineEmits<{ (event: 'edit'): void }>();
</script>

<style scoped lang="scss">
	.profile-row {
		display: flex;
		align-items: center;
		justify-content: space-between;
		margin: 32rpx 0;
	}

	.profile-left {
		flex: 1;
		margin-right: 24rpx;
	}

	.profile-name {
		font-size: 36rpx;
		font-weight: 500;
		color: #0A0A0A;
		line-height: 50rpx;
		display: block;
	}

	.profile-bio {
		margin-top: 16rpx;
		display: flex;
		flex-direction: column;
		gap: 4rpx;
	}

	.profile-bio-line {
		font-size: 24rpx;
		color: #2D323F;
		line-height: 32rpx;
		display: block;
		white-space: pre-wrap;
	}

	.avatar-wrap {
		width: 144rpx;
		height: 144rpx;
		border-radius: 72rpx;
		overflow: hidden;
		flex-shrink: 0;
		border: 1rpx solid #FFF;
	}

	.avatar-img {
		width: 100%;
		height: 100%;
	}


.profile-left { min-width: 0; }
.profile-name, .profile-bio-line { overflow-wrap: anywhere; }
</style>
