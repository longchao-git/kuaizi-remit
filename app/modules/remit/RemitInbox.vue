<template>
  <view class="inbox">
    <view class="line inbox-actions">
      <button class="inline-action accent" :disabled="busy" @tap="load(1)">刷新</button>
      <button class="inline-action accent" :disabled="busy || !latest || !unread" @tap="markAll">全部已读</button>
    </view>
    <text v-if="busy" class="description" role="status">处理中…</text>
    <text v-else-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-else-if="!displayed.length" class="description">暂无消息</text>

    <view v-for="item in displayed" :key="item.reference ?? item.id" class="card inbox-item">
      <view class="line">
        <text class="inbox-title">{{ item.title }}</text>
        <text v-if="!item.read_at" class="pill">未读</text>
      </view>
      <text class="inbox-body">{{ item.body }}</text>
      <view class="line">
        <text class="description">{{ formatTime(item.created_at) }}</text>
        <button v-if="!item.read_at" class="inline-action accent" :disabled="busy" @tap="mark(item.reference ?? item.id)">标为已读</button>
      </view>
      <button v-if="item.support_ticket_id" class="inline-action accent" :disabled="busy" @tap="openTicket(item)">查看工单</button>
      <button v-if="item.order_reference" class="inline-action accent" :disabled="busy" @tap="openOrder(item)">查看订单</button>
    </view>
    <view v-if="pages > 1" class="line inbox-actions">
      <button class="inline-action accent" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
      <text class="description">{{ page }} / {{ pages }}</text>
      <button class="inline-action accent" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import request, { UnauthorizedError } from '@/request/http';

type Notice = {
  id: number;
  reference?: string;
  title: string;
  body: string;
  read_at: string | null;
  created_at: string;
  support_ticket_id?: string | null;
  order_reference?: string | null;
};
type NoticePage = { list: Notice[]; unread_count: number; page: number; total_pages: number };
const emit = defineEmits<{ expired: []; unread: [count: number]; ticket: [id: string]; order: [reference: string] }>();
const props = defineProps<{ targetNotice?: string }>();
const items = ref<Notice[]>([]);
const pushedNotice = ref<Notice>();
const displayed = computed(() => {
  const selected = pushedNotice.value;
  if (!selected) return items.value;
  const reference = (notice: Notice) => notice.reference ?? String(notice.id);
  return [items.value.find(item => reference(item) === reference(selected)) ?? selected,
    ...items.value.filter(item => reference(item) !== reference(selected))];
});
const page = ref(1);
const pages = ref(0);
const latest = ref<string | number>();
const unread = ref(0);
const busy = ref(false);
const error = ref('');
const options = { handleUnauthorized: false };
let reloadPending = false;
let targetPending: string | undefined;
let active = true;

async function query(next: number) {
  const result = await request.get<NoticePage>('app/notifications', { page: next }, options);
  items.value = result.list;
  page.value = result.page;
  pages.value = result.total_pages;
  unread.value = result.unread_count;
  emit('unread', result.unread_count);
  if (next === 1) {
    latest.value = result.list[0]?.reference ?? result.list[0]?.id;
  }
}

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (active && reason instanceof UnauthorizedError) emit('expired');
    error.value = reason instanceof Error ? reason.message : '操作失败，请重试';
  } finally {
    busy.value = false;
    if (active && targetPending) {
      const target = targetPending;
      targetPending = undefined;
      void openPush(target);
    } else if (active && reloadPending) {
      reloadPending = false;
      void load(page.value);
    }
  }
}

function openPush(reference: string) {
  if (busy.value) {
    targetPending = reference;
    return;
  }
  return run(async () => {
    reloadPending = false;
    pushedNotice.value = undefined;
    try {
      pushedNotice.value = await request.get<Notice>('app/notifications/' + reference, {}, options);
    } catch (reason) {
      if (reason instanceof UnauthorizedError) throw reason;
      await query(1);
      throw reason;
    }
    await query(1);
  });
}

function load(next: number) {
  return run(() => query(next));
}

function refresh() {
  if (busy.value) reloadPending = true;
  else void load(page.value);
}

defineExpose({ refresh });

function mark(id: number | string, through = false) {
  return run(async () => {
    await request.post(`app/notifications/${id}/read`, { through }, options);
    if (pushedNotice.value) {
      const pushed = pushedNotice.value.reference ?? pushedNotice.value.id;
      if (String(id) === String(pushed) || (through && String(pushed).padStart(18, '0') <= String(id).padStart(18, '0'))) {
        pushedNotice.value = { ...pushedNotice.value, read_at: new Date().toISOString() };
      }
    }
    await query(through ? 1 : page.value);
  });
}

function markAll() {
  if (latest.value !== undefined) void mark(latest.value, true);
}

function openTicket(item: Notice) {
  const ticket = item.support_ticket_id;
  if (!ticket) return;
  void run(async () => {
    if (!item.read_at) {
      const result = await request.post<{ unread_count: number }>(`app/notifications/${item.reference ?? item.id}/read`, {}, options);
      emit('unread', result.unread_count);
    }
    emit('ticket', ticket);
  });
}

function openOrder(item: Notice) {
  const reference = item.order_reference;
  if (!reference) return;
  void run(async () => {
    if (!item.read_at) {
      const result = await request.post<{ unread_count: number }>(`app/notifications/${item.reference ?? item.id}/read`, {}, options);
      if (!active) return;
      item.read_at = new Date().toISOString();
      unread.value = result.unread_count;
      emit('unread', result.unread_count);
    }
    if (active) emit('order', reference);
  });
}

function formatTime(value: string) {
  return new Date(value).toLocaleString();
}

watch(() => props.targetNotice, (target) => {
  if (target) void openPush(target);
});
onMounted(() => {
  if (props.targetNotice) void openPush(props.targetNotice);
  else void load(1);
});
onUnmounted(() => { active = false; });
</script>

<style scoped lang="scss">
.inbox { display: grid; gap: 20rpx; padding: 24rpx 32rpx; }
.inbox-item { display: grid; gap: 12rpx; padding: 22rpx; min-width: 0; }
.inbox-title { font-weight: 700; overflow-wrap: anywhere; min-width: 0; }
.inbox-body { white-space: pre-wrap; overflow-wrap: anywhere; }
.inbox-actions { gap: 12rpx; }
</style>
