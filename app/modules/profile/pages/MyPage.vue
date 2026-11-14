<template>
	<view class="page">
		<image src="/static/app_my.png" mode="aspectFill" class="page-bg" />
		<view class="header">
			<navbar title="我的" background="transparent" />
			<ProfileHeader :name="displayName" :bio="displayBio" :avatar="userInfo.headImg || '/static/logo.png'" @edit="openEditProfile" />
		</view>
		<MenuList :items="menuItems" @select="handleMenu" />
		<tabbar :selected="1" />
	</view>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import ProfileHeader from '@/components/profile-header/ProfileHeader.vue';
import MenuList from '@/components/menu-list/MenuList.vue';
import { hideNativeTabBar } from '@/shared/navigation/tabs';
import { useMyPage } from '@/modules/profile/application/useMyPage';
const { userInfo, isLoggedIn, displayName, displayBio, initPage, openEditProfile, logout } = useMyPage();
const menuItems = computed(() => [
	{ key: 'profile', label: isLoggedIn.value ? '编辑资料' : '前往登录' },
	...(isLoggedIn.value ? [{ key: 'logout', label: '退出登录' }] : [])
]);
function handleMenu(key: string) {
	if (key === 'profile') openEditProfile();
	if (key === 'logout') void logout();
}
onShow(() => { hideNativeTabBar(); void initPage(); });
</script>

<style scoped lang="scss">
.page {
		min-height: 100vh;
		background: linear-gradient(180deg, rgba(254, 254, 254, 0.30) 3.2%, #F6F7FB 31.99%), linear-gradient(180deg, #FEFEFE 0%, #F6F7FB 55.5%);
		padding-bottom: 20rpx;
	}

	/* ===== Header ===== */
	.header {
		padding: 0 32rpx 0;
		background: transparent;
		position: relative;
		z-index: 2;
	}

	.page-bg {
		position: absolute;
		top: 0;
		left: 0;
		width: 100%;
		height: 100vh;
		z-index: 1;
	}
.page { position: relative; box-sizing: border-box; }
.page-bg { pointer-events: none; }
</style>
