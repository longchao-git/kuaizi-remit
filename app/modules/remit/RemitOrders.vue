<template>
  <view class="body order-list">
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-else-if="busy" class="description">正在读取…</text>
    <text v-else-if="!detailOnly && !items.length && !detail" class="description">暂无汇款订单</text>
    <view v-if="detail" class="card order-card">
      <text class="order-amount">€{{ formatAmount(detail.principal_minor) }}</text>
      <text>{{ detail.status_label }}</text>
      <text class="description">订单编号 {{ detail.reference }}</text>
      <text class="description">下单时间 {{ new Date(detail.created_at).toLocaleString() }}</text>
      <template v-if="detail.quote">
        <view class="line"><text>预计到账</text><text>¥{{ formatAmount(detail.quote.payout_minor) }}</text></view>
        <view class="line"><text>成交汇率</text><text>{{ (detail.quote.rate_ten_thousandths / 10000).toFixed(4) }}</text></view>
        <view class="line"><text>合计支付</text><text>€{{ formatAmount(detail.quote.total_payable_minor) }}</text></view>
      </template>
      <text v-if="detail.provider_checked_at" class="description">
        状态更新时间 {{ new Date(detail.provider_checked_at).toLocaleString() }}
      </text>
      <button v-if="detail.payment_url" class="primary" :disabled="busy" @tap="continuePayment">继续付款</button>
      <button class="inline-action accent" @tap="$emit('support', detail.reference)">咨询这笔订单</button>
    </view>
    <template v-else-if="!detailOnly">
      <button
        v-for="order in items" :key="order.reference" class="card order-card"
        :disabled="busy" @tap="open(order.reference)"
      >
        <text class="order-amount">€{{ formatAmount(order.principal_minor) }}</text>
        <text class="description">{{ order.status_label }} · {{ new Date(order.created_at).toLocaleDateString() }}</text>
        <text class="description">订单编号 {{ order.reference }}</text>
      </button>
      <view v-if="pages > 1" class="line">
        <button class="inline-action" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
        <text>{{ page }} / {{ pages }}</text>
        <button class="inline-action" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
      </view>
    </template>
    <button class="inline-action accent" :disabled="busy" @tap="reload">刷新</button>
  </view>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import request, { UnauthorizedError } from '@/request/http';
import { getErrorMessage } from '@/shared/utils/error';
import { formatAmount } from './previewOrder';

interface Order {
  reference: string;
  principal_minor: number;
  status_label: string;
  provider_checked_at: string | null;
  created_at: string;
  quote?: { payout_minor: number; rate_ten_thousandths: number; total_payable_minor: number };
  payment_url?: string | null;
}

const emit = defineEmits<{ expired: []; detail: [open: boolean]; support: [reference: string] }>();
const props = defineProps<{ initialOrder?: string; detailOnly?: boolean }>();
const path = 'app/remittance/orders';
const options = { handleUnauthorized: false };
const items = ref<Order[]>([]);
const detail = ref<Order>();
const error = ref('');
const busy = ref(false);
const page = ref(1);
const pages = ref(0);
let active = true;

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (reason instanceof UnauthorizedError && active) emit('expired');
    else error.value = getErrorMessage(reason, '读取订单失败，请稍后重试');
  } finally {
    busy.value = false;
  }
}

function load(next: number) {
  return run(async () => {
    const result = await request.get<{ list: Order[]; page: number; total_pages: number }>(path, { page: next }, options);
    items.value = result.list;
    page.value = result.page;
    pages.value = result.total_pages;
  });
}

function open(reference: string) {
  return run(async () => {
    const order = await request.get<Order>(path + '/' + reference, {}, options);
    if (active) {
      detail.value = order;
      emit('detail', true);
    }
  });
}

function continuePayment() {
  const reference = detail.value?.reference;
  if (!reference || busy.value) return;
  // H5 需要在点击时打开窗口，避免异步查询结束后被浏览器拦截。
  // #ifdef H5
  const paymentWindow = window.open('about:blank', '_blank');
  if (!paymentWindow) {
    error.value = '请允许浏览器打开付款页面';
    return;
  }
  paymentWindow.opener = null;
  // #endif
  void run(async () => {
    // #ifdef H5
    let opened = false;
    try {
    // #endif
      const fresh = await request.get<Order>(path + '/' + reference, {}, options);
      detail.value = fresh;
      if (!fresh.payment_url || !/^https:\/\//.test(fresh.payment_url)) {
        throw new Error('付款链接已不可用，请查看最新订单状态');
      }
      // #ifdef APP-PLUS
      plus.runtime.openURL(fresh.payment_url, () => { error.value = '无法打开付款页面'; });
      // #endif
      // #ifdef H5
      paymentWindow.location.replace(fresh.payment_url);
      opened = true;
    } finally {
      if (!opened) paymentWindow.close();
    }
    // #endif
  });
}

function back(): boolean {
  if (busy.value) return true;
  if (!detail.value) return false;
  detail.value = undefined;
  error.value = '';
  emit('detail', false);
  if (!items.value.length) void load(1);
  return true;
}

function reload() {
  const reference = detail.value?.reference ?? (props.detailOnly ? props.initialOrder : undefined);
  return reference ? open(reference) : load(page.value);
}

onMounted(() => props.initialOrder ? open(props.initialOrder) : load(1));
onUnmounted(() => { active = false; });
defineExpose({ back });
</script>

<style scoped>
.order-list { display: grid; gap: 20rpx; }
.order-card { display: grid; gap: 12rpx; padding: 22rpx; text-align: left; width: 100%; line-height: 1.45; }
.order-card .description { overflow-wrap: anywhere; }
.order-amount { font-size: 34rpx; font-weight: 700; }
</style>
