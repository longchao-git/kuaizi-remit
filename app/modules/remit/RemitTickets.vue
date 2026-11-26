<template>
  <view class="body tickets">
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-if="busy" class="description" role="status">处理中…</text>
    <template v-if="editing">
      <input v-model="subject" class="card ticket-input" maxlength="80" aria-label="工单标题" placeholder="工单标题" :disabled="busy" />
      <text v-if="props.orderReference" class="description ticket-body">关联订单 {{ props.orderReference }}</text>
      <textarea v-model="body" class="card ticket-input" maxlength="500" aria-label="问题描述" placeholder="描述遇到的问题" :disabled="busy" />
      <button class="primary" :disabled="busy || !subject.trim() || !body.trim()" @tap="submit">提交工单</button>
    </template>
    <template v-else-if="detail">
      <view class="card ticket-card">
        <view class="line"><text class="ticket-subject">{{ detail.subject }}</text><text class="pill">{{ labels[detail.status] }}</text></view>
        <text v-if="detail.order_reference" class="description ticket-body">关联订单 {{ detail.order_reference }}</text>
        <text class="description">工单 #{{ detail.id }}</text>
        <text class="ticket-body">{{ detail.body }}</text>
      </view>
      <view v-for="update in detail.updates" :key="update.version" class="card ticket-card">
        <view class="line"><text>客服回复</text><text class="pill">{{ labels[update.status] }}</text></view>
        <text class="ticket-body">{{ update.reply }}</text>
        <text class="description">{{ new Date(update.created_at).toLocaleString() }}</text>
      </view>
      <button class="inline-action accent" :disabled="busy" @tap="open(detail.id)">刷新进度</button>
    </template>
    <template v-else>
      <view class="line">
        <button class="inline-action accent" :disabled="busy" @tap="editing = true; error = ''">新建工单</button>
        <button class="inline-action accent" :disabled="busy" @tap="load(page)">刷新</button>
      </view>
      <text v-if="!busy && !error && !items.length" class="description">暂无客服工单</text>
      <button v-for="item in items" :key="item.id" class="card ticket-card ticket-row" :disabled="busy" @tap="open(item.id)">
        <view class="line"><text class="ticket-subject">{{ item.subject }}</text><text class="pill">{{ labels[item.status] }}</text></view>
        <text class="description">工单 #{{ item.id }} · {{ new Date(item.created_at).toLocaleDateString() }}</text>
      </button>
      <view v-if="pages > 1" class="line">
        <button class="inline-action" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
        <text>{{ page }} / {{ pages }}</text>
        <button class="inline-action" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
      </view>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import request, { UnauthorizedError } from '@/request/http';
import { makeMessageId } from './useSupportChat';

type TicketStatus = 'open' | 'processing' | 'resolved';
interface Ticket {
  id: string;
  subject: string;
  order_reference: string | null;
  status: TicketStatus;
  created_at: string;
}
interface TicketDetail extends Ticket {
  body: string;
  updates: { version: number; status: TicketStatus; reply: string; created_at: string }[];
}
const emit = defineEmits<{ expired: [] }>();
const props = defineProps<{ initialTicket?: string; orderReference?: string }>();
const labels = { open: '待处理', processing: '处理中', resolved: '已解决' };
const path = 'app/support/tickets';
const options = { handleUnauthorized: false };
const items = ref<Ticket[]>([]);
const detail = ref<TicketDetail>();
const editing = ref(false);
const subject = ref('');
const body = ref('');
const busy = ref(false);
const error = ref('');
const page = ref(1);
const pages = ref(0);
let pending: { key: string; id: string } | undefined;
let active = true;

async function run(action: () => Promise<void>) {
  if (!active || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (!active) {
      return;
    }
    if (reason instanceof UnauthorizedError) emit('expired');
    else error.value = reason instanceof Error ? reason.message : '操作失败，请重试';
  } finally {
    busy.value = false;
  }
}

async function query(next: number) {
  const result = await request.get<{ list: Ticket[]; page: number; total_pages: number }>(path, { page: next }, options);
  if (!active) return;
  items.value = result.list;
  page.value = result.page;
  pages.value = result.total_pages;
}

function load(next: number) {
  void run(() => query(next));
}

function open(id: string) {
  void run(async () => {
    const result = await request.get<TicketDetail>(`${path}/${id}`, {}, options);
    if (active) detail.value = result;
  });
}

function submit() {
  const input = { subject: subject.value.trim(), body: body.value.trim(), order_reference: props.orderReference };
  if (!input.subject || !input.body) return;
  void run(async () => {
    const key = JSON.stringify(input);
    if (pending?.key !== key) pending = { key, id: makeMessageId() };
    const ticket = await request.post<Ticket>(path, { ...input, client_id: pending.id }, options);
    if (!active) return;
    pending = undefined;
    subject.value = '';
    body.value = '';
    editing.value = false;
    page.value = 1;
    items.value = [ticket, ...items.value.filter(item => item.id !== ticket.id)];
    uni.showToast({ title: '已提交工单', icon: 'none' });
    await query(1);
  });
}

function back(): boolean {
  if (!detail.value && !editing.value) return false;
  if (busy.value) return true;
  if (detail.value) load(page.value);
  detail.value = undefined;
  editing.value = false;
  error.value = '';
  return true;
}

defineExpose({ back });
onMounted(() => {
  if (props.initialTicket) open(props.initialTicket);
  else if (props.orderReference) editing.value = true;
  else load(1);
});
onUnmounted(() => {
  active = false;
});
</script>

<style scoped>
.tickets { display: grid; gap: 16rpx; }
.ticket-input { box-sizing: border-box; width: 100%; min-height: 80rpx; padding: 20rpx; }
textarea.ticket-input { height: 210rpx; }
.ticket-card { display: grid; gap: 12rpx; padding: 20rpx; min-width: 0; }
.ticket-row { width: 100%; text-align: left; line-height: 1.55; }
.ticket-subject, .ticket-body { overflow-wrap: anywhere; }
.ticket-subject { font-weight: 700; }
.ticket-body { white-space: pre-wrap; }
.ticket-card .pill { flex-shrink: 0; }
</style>
