<template>
	<view class="showcase">
		<navbar show-back :intercept-back="interceptBack" @click-back="confirmBack">
			<template #center>组件示例</template>
		</navbar>
		<view class="note">固定展示数据，不代表已接入业务。复用下面的组件，业务通过参数和插槽传入。</view>
		<view class="controls">
			<button size="mini" @click="openPatterns">页面组合与状态 →</button>
			<button size="mini" @click="sheetVisible = true">打开操作面板</button>
			<label><switch :checked="interceptBack" @change="interceptBack = ($event as CustomEvent<{ value: boolean }>).detail.value" />拦截返回</label>
		</view>
		<view class="section">
			<view class="heading">内容卡片</view>
			<ContentCard title="方图内容卡片" description="这里替换业务说明，保留图文比例与间距。" image="/static/logo.png" @click="status = '已点击方图卡片'">
				<template #footer><text class="metadata">自定义底部信息</text></template>
			</ContentCard>
			<ContentCard landscape title="这是一个用于验证两行截断效果的长标题，后续业务替换文案即可，不需要重新设计卡片" description="横图模式保留源项目 280 × 158rpx 的封面比例。" @click="status = '已点击横图卡片'" />
		</view>
		<view class="section">
			<view class="heading">空态与异常态</view>
			<view class="controls">
				<button v-for="item in variants" :key="item.value" size="mini" @click="variant = item.value">{{ item.label }}</button>
			</view>
			<EmptyState :variant="variant" :title="variant === 'network' ? '连接失败' : '暂无内容'" desc="这里填写可执行的下一步说明" button-text="再试一次" @action="status = '已触发空态操作'" />
		</view>
		<view class="status">{{ status }}</view>
		<ActionSheet v-model:visible="sheetVisible" title="选择操作" :item-list="[{ name: '查看详情' }, { name: '暂不可用', disabled: true }]" @select="status = '已选择操作 ' + ($event + 1)" />
	</view>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import EmptyState from '@/components/empty-state/empty-state.vue';
import ActionSheet from '@/components/action-sheet/ActionSheet.vue';
import ContentCard from '@/components/content-card/ContentCard.vue';
const sheetVisible = ref(false);
function openPatterns() { uni.navigateTo({ url: '/subPackages/showcase/pages/PatternShowcasePage' }); }
const interceptBack = ref(false);
const status = ref('等待操作');
const variant = ref<'default' | 'message' | 'network'>('default');
const variants = [
	{ value: 'default', label: '默认' },
	{ value: 'message', label: '消息' },
	{ value: 'network', label: '网络' }
] as const;
function confirmBack() {
	uni.showModal({
		title: '离开示例？',
		content: '这里演示未保存内容时的返回确认。',
		success: result => { if (result.confirm) uni.navigateBack(); }
	});
}
</script>

<style scoped lang="scss">
@import '@/uni.scss';
.showcase { min-height: 100vh; padding: 0 32rpx calc(32rpx + env(safe-area-inset-bottom)); background: $app-page-bg; box-sizing: border-box; }
.section { padding: 24rpx; margin-top: 24rpx; border-radius: 16rpx; background: $app-card-bg; }
.heading { font-size: 32rpx; font-weight: 500; margin-bottom: 24rpx; }
.note, .status, .metadata { color: $app-text-secondary; font-size: 24rpx; line-height: 36rpx; }
.controls { display: flex; flex-wrap: wrap; gap: 16rpx; align-items: center; margin: 24rpx 0; }
.controls button { margin: 0; color: $app-primary; }
.controls label { font-size: 24rpx; display: flex; align-items: center; }
.status { margin-top: 24rpx; }
</style>
