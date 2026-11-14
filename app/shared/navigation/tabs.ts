import pages from '@/pages.json';

// 页面注册与自定义导航共用一个配置，业务只改 pages.json 的 tabBar.list。
export const appTabs = pages.tabBar.list.map(item => ({
	...item,
	pagePath: '/' + item.pagePath
}));

export function hideNativeTabBar(): void {
	uni.hideTabBar({
		animation: false,
		fail: () => uni.showToast({ title: '底部导航初始化失败', icon: 'none' })
	});
}
