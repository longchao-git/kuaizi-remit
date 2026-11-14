<template>
  <view class="prototype chat-body">
    <text class="chat-online">{{ live ? '实时更新' : '客服消息' }}</text>
    <text v-if="status" class="description" role="alert">{{ status }}</text>
    <button v-if="!live || status" class="text-link chat-refresh" @tap="$emit('refresh')">刷新消息</button>
    <scroll-view class="chat-scroll support-scroll" scroll-y :scroll-into-view="scrollTarget">
      <view class="chat">
        <text v-if="!messages.length && !status" class="description">暂无消息</text>
        <text
          v-for="message in messages" :id="`local-chat-${message.id}`" :key="message.id"
          class="bubble" :class="{ own: message.sender === 'member' }"
        >{{ message.body }}</text>
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

const props = defineProps<{
  live: boolean;
  status: string;
  sending: boolean;
  messages: { id: number; sender: 'staff' | 'member'; body: string }[];
  send: (body: string) => Promise<boolean>;
}>();
defineEmits<{ refresh: [] }>();
const draft = ref('');
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
  margin-bottom: 16rpx;
}
.chat-footer { flex-shrink: 0; }
</style>
