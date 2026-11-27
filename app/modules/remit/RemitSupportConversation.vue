<template>
  <view class="prototype chat-body">
    <text v-if="status" class="description" role="alert">{{ status }}</text>
    <scroll-view class="chat-scroll support-scroll" scroll-y :scroll-into-view="scrollTarget">
      <view class="chat">
        <text v-if="!messages.length && !status" class="description">暂无消息</text>
        <template v-for="{ message, timestamp } in entries" :key="message.id">
          <text v-if="timestamp" class="chat-date">{{ timestamp }}</text>
          <text :id="`local-chat-${message.id}`" class="bubble" :class="{ own: message.sender === 'member' }">
            {{ message.body }}
          </text>
        </template>
      </view>
    </scroll-view>
    <view class="line chat-input chat-footer">
      <input v-model="draft" placeholder="输入客服消息" aria-label="输入客服消息" maxlength="500" @confirm="submit" />
      <button class="inline-action accent" :disabled="!draft.trim() || sending" @tap="submit">发送</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import type { SupportMessage } from './useSupportChat';
import { formatRemitTimestamp, localDateKey } from './previewOrder';

const props = defineProps<{
  status: string;
  sending: boolean;
  messages: SupportMessage[];
  send: (body: string) => Promise<boolean>;
}>();
const draft = ref('');
const entries = computed(() => {
  let previous = '';
  return props.messages.map(message => {
    const date = message.created_at ? new Date(message.created_at) : undefined;
    if (!date || Number.isNaN(date.getTime())) return { message, timestamp: '' };
    const key = localDateKey(date);
    if (key === previous) return { message, timestamp: '' };
    previous = key;
    const day = key === localDateKey() ? '今天' : key;
    const time = formatRemitTimestamp(date, 'time');
    return { message, timestamp: `${day} ${time}` };
  });
});
const scrollTarget = computed(() => {
  const last = props.messages[props.messages.length - 1];
  return last ? `local-chat-${last.id}` : '';
});

async function submit() {
  const text = draft.value.trim();
  if (!text || props.sending) return;
  if (await props.send(text) && draft.value.trim() === text) draft.value = '';
}
</script>

<style scoped lang="scss" src="./remit-prototype.scss"></style>

<style scoped lang="scss">
.support-scroll {
  height: 0;
  margin-top: 0;
  margin-bottom: 16rpx;
}
.chat-date {
  align-self: center;
  color: #6d7180;
  font-size: 22rpx;
}
.bubble {
  align-self: flex-start;
  box-sizing: border-box;
  max-width: 84%;
  border: 1rpx solid #e7e7ef;
  border-radius: 16rpx 38rpx 38rpx 38rpx;
  padding: 22rpx 30rpx;
  color: #111318;
  font-size: 26rpx;
  line-height: 1.55;
}
.bubble.own {
  border-color: transparent;
  border-radius: 38rpx 16rpx 38rpx 38rpx;
}
.chat-footer {
  flex-shrink: 0;
  min-height: 104rpx;
  padding: 0 28rpx;
  border: 1rpx solid #e7e7ef;
  border-radius: 34rpx;
  margin-bottom: env(safe-area-inset-bottom);
}
</style>
