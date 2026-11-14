<template>
	<view v-if="visible" class="action-sheet-root" @touchmove.stop.prevent>
		<view class="action-sheet-overlay" @click="close"></view>
		<view class="action-sheet-container">
			<view v-if="title" class="action-sheet-title">{{ title }}</view>
			<view class="action-sheet-list">
				<view
					v-for="(item, index) in itemList"
					:key="index"
					class="action-sheet-item"
					:class="{ 'action-sheet-item-disabled': item.disabled }"
					@click="handleSelect(index)"
				>
					{{ item.name }}
				</view>
			</view>
			<!-- 分割线 -->
			<view class="action-sheet-divider"></view>
			<view class="action-sheet-cancel" @click="close">取消</view>
		</view>
	</view>
</template>

<script setup lang="ts">
export interface ActionSheetItem {
	name: string;
	disabled?: boolean;
}

const props = defineProps<{
	visible: boolean;
	itemList: ActionSheetItem[];
	title?: string;
}>();

const emit = defineEmits<{
	(event: 'update:visible', value: boolean): void;
	(event: 'select', index: number): void;
	(event: 'close'): void;
}>();

const close = () => {
	emit('update:visible', false);
	emit('close');
};

const handleSelect = (index: number) => {
	if (props.itemList[index]?.disabled) return;
	close();
	emit('select', index);
};
</script>

<style scoped lang="scss">
.action-sheet-overlay {
	position: absolute;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	background: rgba(0, 0, 0, 0.5);
}

.action-sheet-root {
	position: fixed;
	top: 0;
	left: 0;
	right: 0;
	bottom: 0;
	z-index: 9999;
}

.action-sheet-container {
	position: absolute;
	bottom: 0;
	left: 0;
	right: 0;
	padding-bottom: env(safe-area-inset-bottom);
	background: #ffffff;
	border-radius: 24rpx 24rpx 0 0;
}

.action-sheet-title {
	padding: 32rpx 32rpx 24rpx;
	text-align: center;
	font-size: 26rpx;
	color: #999999;
	border-bottom: 1rpx solid #eeeeee;
}

.action-sheet-list {
	padding: 16rpx 0;
}

.action-sheet-item {
	height: 100rpx;
	line-height: 100rpx;
	text-align: center;
	font-size: 32rpx;
	color: #333333;
	position: relative;

	&::after {
		content: '';
		position: absolute;
		left: 0rpx;
		right: 0rpx;
		bottom: 0;
		height: 1rpx;
		background: #eeeeee;
	}

	&:last-child::after {
		display: none;
	}
}

.action-sheet-item-disabled {
	color: #cccccc;
}

.action-sheet-divider {
	height: 12rpx;
	background: #f5f5f5;
}

.action-sheet-cancel {
	height: 100rpx;
	line-height: 100rpx;
	text-align: center;
	font-size: 32rpx;
	color: #333333;
	background: #ffffff;
	border-radius: 0;
}
</style>
