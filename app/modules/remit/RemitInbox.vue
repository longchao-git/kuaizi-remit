<template>
  <view class="inbox">
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-else-if="hasLoaded && !busy && !displayed.length" class="description">暂无消息</text>

    <template v-for="{ item, icon, dateLabel, timeLabel } in datedNotices" :key="item.reference ?? item.id">
      <text v-if="dateLabel" class="inbox-date">{{ dateLabel }}</text>
      <view class="card inbox-item">
        <button
          class="inbox-entry"
          :aria-label="item.read_at ? undefined : '未读：' + item.title"
          :disabled="busy"
          @tap="openNotice(item)"
        >
          <view class="inbox-icon" :class="'inbox-icon--' + icon" aria-hidden="true">
            <view v-if="icon === 'arrival'" class="inbox-check" />
            <text v-else-if="icon === 'payment'" class="inbox-symbol">€</text>
            <text v-else-if="icon === 'security'" class="inbox-symbol">!</text>
            <image v-else :src="noticeImages[icon]" mode="aspectFit" />
          </view>
          <view class="inbox-copy">
            <text class="inbox-title">{{ item.title }}</text>
            <text class="inbox-body">{{ item.body }}</text>
            <text class="inbox-time">{{ timeLabel }}</text>
          </view>
          <view v-if="!item.read_at" class="inbox-unread" aria-hidden="true" />
        </button>
        <view v-if="hasExtraLink(item)" class="inbox-item-actions">
          <button v-if="item.material_reference && item.support_ticket_id" class="inline-action accent" :disabled="busy" @tap="openTicket(item)">查看工单</button>
          <button v-if="item.order_reference" class="inline-action accent" :disabled="busy" @tap="openOrder(item)">查看订单</button>
        </view>
      </view>
    </template>
    <view v-if="pages > 1" class="line inbox-actions">
      <button class="inline-action accent" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
      <text class="description">{{ page }} / {{ pages }}</text>
      <button class="inline-action accent" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
    </view>
    <button class="inline-action accent inbox-refresh" :disabled="busy" @tap="load(1)">刷新</button>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import request, { UnauthorizedError } from '@/request/http';
import { formatRemitTimestamp, localDateKey } from './previewOrder';
import { useRemitLoading } from './useRemitLoading';

type Notice = {
  id: number;
  reference?: string;
  title: string;
  body: string;
  read_at: string | null;
  created_at: string;
  support_ticket_id?: string | null;
  order_reference?: string | null;
  material_reference?: string | null;
};
type NoticePage = { list: Notice[]; unread_count: number; page: number; total_pages: number };
const noticeImages = {
  refund: '/static/remit/status-refund.svg',
  warning: '/static/remit/status-warning.svg',
  coupon: '/static/remit/coupon.svg',
  ticket: '/static/remit/support.svg',
  document: '/static/remit/document.svg',
  message: '/static/remit/mail.svg',
} as const;
type NoticeIcon = keyof typeof noticeImages | 'arrival' | 'payment' | 'security';

const emit = defineEmits<{ expired: []; unread: [count: number]; ticket: [id: string]; order: [reference: string]; material: [reference: string] }>();
const props = defineProps<{ targetNotice?: string; pageVisible: boolean }>();
const items = ref<Notice[]>([]);
const hasLoaded = ref(false);
const pushedNotice = ref<Notice>();
const displayed = computed(() => {
  const selected = pushedNotice.value;
  if (!selected) return items.value;
  const reference = (notice: Notice) => notice.reference ?? String(notice.id);
  return [items.value.find(item => reference(item) === reference(selected)) ?? selected,
    ...items.value.filter(item => reference(item) !== reference(selected))];
});
const datedNotices = computed(() => {
  const today = new Date();
  const yesterday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - 1);
  const todayKey = localDateKey(today);
  const yesterdayKey = localDateKey(yesterday);
  let previous = '';
  return displayed.value.map(item => {
    const createdAt = new Date(item.created_at);
    const date = localDateKey(createdAt);
    const dateLabel = date === previous ? '' : date === todayKey ? '今天' : date === yesterdayKey ? '昨天' : date;
    const timeLabel = date === todayKey
      ? formatRemitTimestamp(createdAt, 'time')
      : `${createdAt.getMonth() + 1}/${createdAt.getDate()}`;
    previous = date;
    return { item, icon: noticeIcon(item), dateLabel, timeLabel };
  });
});
const page = ref(1);
const pages = ref(0);
const latest = ref<string | number>();
const unread = ref(0);
const busy = ref(false);
const foregroundBusy = ref(false);
useRemitLoading(() => foregroundBusy.value && props.pageVisible);
const error = ref('');
const canMarkAll = computed(() => !busy.value && latest.value !== undefined && unread.value > 0);
const options = { handleUnauthorized: false };
let reloadPending = false;
let targetPending: string | undefined;
let active = true;

async function query(next: number) {
  const result = await request.get<NoticePage>('app/notifications', { page: next }, options);
  if (!active) return;
  items.value = result.list;
  hasLoaded.value = true;
  page.value = result.page;
  pages.value = result.total_pages;
  unread.value = result.unread_count;
  emit('unread', result.unread_count);
  if (next === 1) {
    latest.value = result.list[0]?.reference ?? result.list[0]?.id;
  }
}

async function run(action: () => Promise<void>, showLoading = true) {
  if (!active || busy.value) return;
  busy.value = true;
  error.value = '';
  foregroundBusy.value = showLoading;
  try {
    await action();
  } catch (reason) {
    if (!active) return;
    if (active && reason instanceof UnauthorizedError) emit('expired');
    error.value = reason instanceof Error ? reason.message : '操作失败，请重试';
  } finally {
    foregroundBusy.value = false;
    busy.value = false;
    if (active && targetPending) {
      const target = targetPending;
      targetPending = undefined;
      void openPush(target);
    } else if (active && reloadPending) {
      reloadPending = false;
      void load(page.value, false);
    }
  }
}

function openPush(reference: string) {
  if (!active) return;
  if (busy.value) {
    targetPending = reference;
    return;
  }
  return run(async () => {
    reloadPending = false;
    pushedNotice.value = undefined;
    try {
      const result = await request.get<Notice>('app/notifications/' + reference, {}, options);
      if (!active) return;
      pushedNotice.value = result;
    } catch (reason) {
      if (reason instanceof UnauthorizedError) throw reason;
      await query(1);
      throw reason;
    }
    await query(1);
  });
}

function load(next: number, showLoading = true) {
  return run(() => query(next), showLoading);
}

function refresh() {
  if (busy.value) reloadPending = true;
  else void load(page.value, false);
}

defineExpose({ refresh, markAll, canMarkAll });

function mark(id: number | string, through = false) {
  return run(async () => {
    await request.post(`app/notifications/${id}/read`, { through }, options);
    if (!active) return;
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
  if (canMarkAll.value && latest.value !== undefined) void mark(latest.value, true);
}

function noticeIcon(item: Notice): NoticeIcon {
  if (item.material_reference) return 'document';
  if (item.support_ticket_id) return 'ticket';
  if (item.order_reference) {
    if (item.title === '汇款订单已退款') return 'refund';
    if (item.title === '已确认收到汇款付款') return 'payment';
    if (/已下发|已到账/.test(item.title)) return 'arrival';
    if (/异常|处理失败/.test(item.title)) return 'warning';
    return 'document';
  }
  if (/^(安全提醒|风险提醒)(?:$|[：: ·])/.test(item.title)) return 'security';
  if (/^(优惠券|费用优惠)|手续费(?:半价|优惠)/.test(item.title)) return 'coupon';
  return 'message';
}

function hasExtraLink(item: Notice): boolean {
  return Boolean(
    (item.order_reference && (item.material_reference || item.support_ticket_id))
    || (item.material_reference && item.support_ticket_id),
  );
}

function openNotice(item: Notice) {
  if (item.material_reference) openMaterial(item);
  else if (item.support_ticket_id) openTicket(item);
  else if (item.order_reference) openOrder(item);
  else if (!item.read_at) void mark(item.reference ?? item.id);
}

async function markLinkedRead(item: Notice) {
  if (item.read_at) return;
  const result = await request.post<{ unread_count: number }>(`app/notifications/${item.reference ?? item.id}/read`, {}, options);
  if (!active) return;
  item.read_at = new Date().toISOString();
  unread.value = result.unread_count;
  emit('unread', result.unread_count);
}

function openTicket(item: Notice) {
  const ticket = item.support_ticket_id;
  if (!ticket) return;
  void run(async () => {
    await markLinkedRead(item);
    if (active) emit('ticket', ticket);
  });
}

function openOrder(item: Notice) {
  const reference = item.order_reference;
  if (!reference) return;
  void run(async () => {
    await markLinkedRead(item);
    if (active) emit('order', reference);
  });
}

function openMaterial(item: Notice) {
  const reference = item.material_reference;
  if (!reference) return;
  void run(async () => {
    await markLinkedRead(item);
    if (active) emit('material', reference);
  });
}

watch(() => props.targetNotice, (target) => {
  if (target) void openPush(target);
});
onMounted(() => {
  if (props.targetNotice) void openPush(props.targetNotice);
  else void load(1);
});
onUnmounted(() => {
  active = false;
  reloadPending = false;
  targetPending = undefined;
});
</script>

<style scoped lang="scss">
.inbox {
  display: grid;
  align-content: start;
  gap: 24rpx;
  padding: 44rpx 40rpx 32rpx;
}
.inbox-date {
  padding-top: 20rpx;
  color: #6d7180;
  font-size: 22rpx;
  font-weight: 500;
}
.inbox-item {
  border: 1.5rpx solid #e6e6ee;
  border-radius: 36rpx;
  box-shadow: 0 2rpx 4rpx rgba(31, 35, 48, 0.035);
}
.inbox-entry,
.inbox-entry[disabled] {
  display: grid;
  grid-template-columns: 86rpx minmax(0, 1fr) 16rpx;
  align-items: center;
  gap: 22rpx;
  width: 100%;
  min-height: 166rpx;
  padding: 22rpx 32rpx;
  color: #111318;
  background: transparent;
  text-align: left;
  line-height: 1.45;
}
.inbox-icon {
  display: grid;
  place-items: center;
  width: 86rpx;
  height: 86rpx;
  border-radius: 30rpx;
  background: #eeebff;
}
.inbox-icon--arrival {
  background: #e7f7ef;
}
.inbox-icon--warning,
.inbox-icon--coupon {
  background: #fff0f1;
}
.inbox-icon--refund {
  background: #fff1e6;
}
.inbox-check {
  width: 16rpx;
  height: 30rpx;
  border: solid #329c65;
  border-width: 0 6rpx 6rpx 0;
  border-radius: 3rpx;
  transform: translateY(-4rpx) rotate(40deg);
}
.inbox-icon image {
  width: 40rpx;
  height: 40rpx;
}
.inbox-symbol {
  color: var(--remit-accent);
  font-size: 40rpx;
  font-weight: 700;
}
.inbox-copy {
  display: grid;
  gap: 3rpx;
  min-width: 0;
}
.inbox-title {
  font-size: 28rpx;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.inbox-unread {
  width: 14rpx;
  height: 14rpx;
  border-radius: 50%;
  background: var(--remit-accent);
}
.inbox-body,
.inbox-time {
  color: #6d7180;
  font-size: 21rpx;
}
.inbox-body {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.inbox-item-actions {
  display: flex;
  gap: 18rpx;
  flex-wrap: wrap;
  margin: 0 30rpx 12rpx 138rpx;
}
.inbox-refresh {
  justify-self: start;
}
</style>
