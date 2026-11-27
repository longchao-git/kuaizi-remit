<template>
  <view class="body order-list" :class="{ 'figma-order-page': usesFigmaSurface }">
    <view v-if="!detail && !detailOnly" class="order-filters" role="group" aria-label="订单状态筛选">
      <button
        v-for="filter in filters" :key="filter.value" class="order-filter"
        :class="{ 'is-selected': selectedFilter === filter.value }"
        :aria-pressed="selectedFilter === filter.value" :disabled="busy" @tap="selectFilter(filter.value)"
      >{{ filter.label }}</button>
    </view>
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-else-if="busy" class="description">正在读取…</text>
    <text v-else-if="!detailOnly && !items.length && !detail" class="description">
      {{ selectedFilter === 'all' ? '暂无汇款订单' : '暂无该状态的汇款订单' }}
    </text>
    <view v-if="detail" class="order-detail" :class="{ 'standard-detail': standardDetail }">
      <template v-if="orderStage === 'payment-review'">
        <view class="order-summary progress-hero">
          <view class="order-stage-symbol" aria-hidden="true">
            <image src="/static/remit/status-refresh.svg" mode="aspectFit" />
          </view>
          <text class="heading">正在确认你的付款</text>
          <text class="order-meta">系统正在核对本笔订单的付款信息</text>
        </view>
        <view class="card order-breakdown payment-check-card">
          <view class="line"><text>订单号</text><text>{{ detail.reference }}</text></view>
          <view v-if="detail.quote" class="line">
            <text>应付金额</text><text>€{{ formatAmount(detail.quote.total_payable_minor) }}</text>
          </view>
          <view class="line"><text>下单时间</text><text>{{ formatRemitTimestamp(detail.created_at) }}</text></view>
        </view>
        <view class="line progress-check-row">
          <text>可稍后查询</text>
          <button class="inline-action accent" :disabled="busy" @tap="reload">刷新状态</button>
        </view>
        <text class="progress-notice">请勿重复付款。如需核验付款凭证，可联系筷子闪汇客服。</text>
        <view class="order-actions progress-actions">
          <button v-if="detail.payment_url" class="primary" :disabled="busy" @tap="continuePayment">继续付款</button>
          <button class="secondary progress-home" @tap="$emit('remit')">返回首页</button>
          <button class="secondary" @tap="$emit('support', detail.reference)">联系客服</button>
        </view>
      </template>
      <template v-else-if="orderStage === 'processing'">
        <view class="order-summary is-processing">
          <text class="order-amount">{{ detail.quote ? `¥${formatAmount(detail.quote.payout_minor)}` : `€${formatAmount(detail.principal_minor)}` }}</text>
          <text class="order-status tone-progress">{{ displayStatus(detail) }}</text>
          <text class="order-meta">{{ detail.quote ? '报价到账金额' : '汇款本金' }}</text>
        </view>
        <view class="card order-progress" aria-label="汇款进度">
          <view v-for="(step, index) in processingSteps" :key="step" class="order-progress-step" :class="{ 'is-current': index === 2 }">
            <view class="order-progress-mark" :class="{ 'is-done': index < 2 }" aria-hidden="true" />
            <view>
              <text class="block">{{ step }}</text>
              <text v-if="index === 0" class="order-meta">{{ formatRemitTimestamp(detail.created_at, 'time') }}</text>
              <text v-else-if="index === 1" class="order-meta">付款已确认</text>
              <text v-else-if="index === 2" class="order-meta">正在处理</text>
              <text v-else-if="index === 3" class="order-meta">等待处理</text>
            </view>
          </view>
        </view>
        <view class="line card progress-help">
          <image class="progress-help-icon" src="/static/remit/support.svg" mode="aspectFit" aria-hidden="true" />
          <view class="progress-help-copy">
            <text class="block">需要帮助？</text>
            <text class="order-meta">客服可协助查询本笔汇款</text>
          </view>
          <button class="inline-action accent" @tap="$emit('support', detail.reference)">客服 ›</button>
        </view>
        <view class="order-actions progress-actions">
          <button class="inline-action accent progress-refresh" :disabled="busy" @tap="reload">刷新状态</button>
          <button class="secondary" @tap="$emit('support', detail.reference)">联系筷子闪汇客服</button>
        </view>
      </template>
      <template v-else-if="showingSentResult">
        <view class="order-summary progress-hero sent-hero">
          <view class="order-stage-symbol is-sent" aria-hidden="true"><view class="refund-tick" /></view>
          <text class="order-amount">{{ detail.quote ? `¥${formatAmount(detail.quote.payout_minor)}` : `€${formatAmount(detail.principal_minor)}` }}</text>
          <text class="order-meta">{{ detail.quote ? '报价到账金额' : '汇款本金' }}</text>
        </view>
        <text class="order-status tone-success sent-status">{{ displayStatus(detail) }}</text>
        <view class="card order-breakdown sent-breakdown">
          <view v-if="detail.provider_checked_at" class="line">
            <text>状态更新时间</text>
            <text>{{ formatRemitTimestamp(detail.provider_checked_at) }}</text>
          </view>
          <view class="line"><text>汇款本金</text><text>€{{ formatAmount(detail.principal_minor) }}</text></view>
          <template v-if="detail.quote">
            <view v-if="detail.quote.base_fee_minor !== undefined" class="line"><text>汇款手续费</text><text>€{{ formatAmount(detail.quote.base_fee_minor) }}</text></view>
            <view class="line"><text>报价汇率</text><text>1 EUR = {{ (detail.quote.rate_ten_thousandths / 10000).toFixed(4) }} CNY</text></view>
            <view class="line"><text>合计支付</text><text>€{{ formatAmount(detail.quote.total_payable_minor) }}</text></view>
          </template>
        </view>
        <text class="progress-notice sent-notice">汇款已下发，实际到账请以收款账户记录为准。</text>
        <view class="order-actions progress-actions">
          <button class="primary" @tap="$emit('remit')">再次汇款</button>
          <button class="secondary" @tap="showSentResult = false">查看订单详情</button>
        </view>
      </template>
      <template v-else>
        <view v-if="refundStatus" class="refund-summary">
          <view class="refund-symbol" :class="{ completed: refundStatus === 'complete' }" aria-hidden="true">
            <view v-if="refundStatus === 'complete'" class="refund-tick" />
            <image v-else src="/static/remit/status-refund.svg" mode="aspectFit" />
          </view>
          <text v-if="refundStatus === 'complete'" class="order-status tone-success">退款完成</text>
          <text class="refund-title">{{ displayStatus(detail) }}</text>
        </view>
        <view v-else class="order-summary">
          <text class="order-status" :class="statusTone(detail)">{{ displayStatus(detail) }}</text>
          <text class="order-amount">
            {{ detail.quote ? `¥${formatAmount(detail.quote.payout_minor)}` : `€${formatAmount(detail.principal_minor)}` }}
          </text>
          <text class="order-meta">{{ detail.quote ? '报价到账金额' : '汇款本金' }}</text>
        </view>
        <view v-if="refundStatus" class="card order-breakdown">
          <view class="line"><text>原订单号</text><text>{{ detail.reference }}</text></view>
          <view class="line"><text>下单时间</text><text>{{ formatRemitTimestamp(detail.created_at) }}</text></view>
          <view v-if="detail.provider_checked_at" class="line">
            <text>状态更新时间</text><text>{{ formatRemitTimestamp(detail.provider_checked_at) }}</text>
          </view>
        </view>
        <text v-if="refundStatus" class="progress-notice refund-notice" :class="{ 'is-complete': refundStatus === 'complete' }">
          {{ refundStatus === 'complete' ? '可在原付款账户的明细中核对退款。' : '退款进度以订单状态为准。如需帮助，可咨询筷子闪汇客服。' }}
        </text>
        <button v-if="refundStatus && detail.quote" class="inline-action accent" @tap="showOriginalQuote = !showOriginalQuote">
          {{ showOriginalQuote ? '收起原订单费用' : '查看原订单费用' }}
        </button>
        <view v-if="!refundStatus || (showOriginalQuote && detail.quote)" class="card order-breakdown">
          <template v-if="!refundStatus">
            <view class="line"><text>订单编号</text><text>{{ detail.reference }}</text></view>
            <view class="line"><text>下单时间</text><text>{{ formatRemitTimestamp(detail.created_at) }}</text></view>
          </template>
          <view class="line"><text>汇款本金</text><text>€{{ formatAmount(detail.principal_minor) }}</text></view>
          <template v-if="detail.quote">
            <view class="line">
              <text>报价汇率</text>
              <text>1 EUR = {{ (detail.quote.rate_ten_thousandths / 10000).toFixed(4) }} CNY</text>
            </view>
            <view v-if="detail.quote.base_fee_minor !== undefined" class="line"><text>汇款手续费</text><text>€{{ formatAmount(detail.quote.base_fee_minor) }}</text></view>
            <view v-if="detail.quote.discount_minor" class="line"><text>优惠减免</text><text>−€{{ formatAmount(detail.quote.discount_minor) }}</text></view>
            <view v-if="detail.quote.channel_fee_minor !== undefined" class="line"><text>付款通道费</text><text>€{{ formatAmount(detail.quote.channel_fee_minor) }}</text></view>
            <view class="line order-total"><text>合计支付</text><text>€{{ formatAmount(detail.quote.total_payable_minor) }}</text></view>
          </template>
        </view>
        <text v-if="!refundStatus && detail.provider_checked_at" class="description">
          状态更新时间 {{ formatRemitTimestamp(detail.provider_checked_at) }}
        </text>
        <view class="order-actions">
          <button v-if="detail.payment_url" class="primary" :disabled="busy" @tap="continuePayment">继续付款</button>
          <button v-if="orderStage === 'sent'" class="inline-action accent" :disabled="busy" @tap="showSentResult = !showSentResult">
            查看汇款结果
          </button>
          <view class="line order-action-pair">
            <button class="secondary" @tap="$emit('support', detail.reference)">咨询这笔订单</button>
            <button v-if="refundStatus" class="primary" :disabled="busy" @tap="$emit('leave')">
              {{ detailOnly ? '返回消息' : '返回订单列表' }}
            </button>
            <button v-else-if="detail.status_label === '已下发'" class="primary" :disabled="busy" @tap="$emit('remit')">
              再次汇款
            </button>
          </view>
          <button class="inline-action accent" :disabled="busy" @tap="reload">刷新状态</button>
        </view>
      </template>
    </view>
    <template v-else-if="!detailOnly">
      <button
        v-for="order in items" :key="order.reference" class="card order-card"
        :disabled="busy" @tap="open(order.reference)"
      >
        <view class="line order-card-top">
          <text class="order-status" :class="statusTone(order)">{{ displayStatus(order) }}</text>
          <text class="order-meta">{{ formatRemitTimestamp(order.created_at) }}</text>
        </view>
        <view class="line order-card-value">
          <text class="order-card-label">{{ order.quote && !isRefund(order) ? '报价到账金额' : '汇款本金' }}</text>
          <view class="order-card-amounts">
            <template v-if="order.quote && !isRefund(order)">
              <text class="order-amount">¥{{ formatAmount(order.quote.payout_minor) }}</text>
              <text class="order-meta">€{{ formatAmount(order.principal_minor) }}</text>
            </template>
            <text v-else class="order-amount">€{{ formatAmount(order.principal_minor) }}</text>
          </view>
        </view>
        <view class="line order-card-reference">
          <text class="order-meta">订单编号 {{ order.reference }}</text>
          <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
        </view>
      </button>
      <view v-if="pages > 1" class="line">
        <button class="inline-action" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
        <text>{{ page }} / {{ pages }}</text>
        <button class="inline-action" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
      </view>
    </template>
    <button v-if="!detail" class="inline-action accent" :disabled="busy" @tap="reload">刷新</button>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import request, { UnauthorizedError } from '@/request/http';
import { getErrorMessage } from '@/shared/utils/error';
import { formatAmount, formatRemitTimestamp } from './previewOrder';

interface Order {
  reference: string;
  principal_minor: number;
  status_label: string;
  simulated?: boolean;
  provider_checked_at: string | null;
  created_at: string;
  quote?: {
    payout_minor: number;
    rate_ten_thousandths: number;
    total_payable_minor: number;
    base_fee_minor?: number;
    discount_minor?: number;
    channel_fee_minor?: number;
  };
  payment_url?: string | null;
  can_refresh?: boolean;
  income_code?: string;
  outgoing_code?: string;
  refund_code?: string;
}

function displayStatus(order: Order): string {
  return order.simulated ? `模拟 · ${order.status_label}` : order.status_label;
}

const emit = defineEmits<{
  expired: [];
  detail: [open: boolean];
  support: [reference: string];
  leave: [];
  remit: [];
}>();
const props = withDefaults(defineProps<{ initialOrder?: string; detailOnly?: boolean; pageVisible?: boolean }>(), { pageVisible: true });
const path = 'app/remittance/orders';
const options = { handleUnauthorized: false };
const filters = [
  { value: 'all', label: '全部' },
  { value: 'processing', label: '处理中' },
  { value: 'completed', label: '已完成' },
  { value: 'refund', label: '退款' },
] as const;
type OrderFilter = (typeof filters)[number]['value'];
const selectedFilter = ref<OrderFilter>('all');
const items = ref<Order[]>([]);
const detail = ref<Order>();
const showOriginalQuote = ref(false);
const showSentResult = ref(Boolean(props.initialOrder) && !props.detailOnly);
const refundStatus = computed(() => {
  const order = detail.value;
  if (!order || !isRefund(order)) return undefined;
  return order.refund_code === '04' ? 'complete' : 'other';
});
const orderStage = computed(() => {
  const order = detail.value;
  if (!order || isRefund(order)) return undefined;
  if (order.status_label === '已下发') return 'sent';
  if (order.status_label === '已付款，汇款处理中') return 'processing';
  if (order.status_label === '付款审核中') return 'payment-review';
  return undefined;
});
const showingSentResult = computed(() => orderStage.value === 'sent' && showSentResult.value);
const usesFigmaSurface = computed(() => showingSentResult.value || orderStage.value === 'processing' || orderStage.value === 'payment-review');
const hasWhiteHeader = computed(() => showingSentResult.value || orderStage.value === 'processing');
const standardDetail = computed(() =>
  !refundStatus.value && !showingSentResult.value && orderStage.value !== 'processing' && orderStage.value !== 'payment-review',
);
const detailTitle = computed(() => {
  if (refundStatus.value === 'complete') return '处理结果';
  if (refundStatus.value) return '退款进度';
  if (showingSentResult.value) return '汇款结果';
  if (orderStage.value === 'processing' || orderStage.value === 'payment-review') return '订单进度';
  return '订单详情';
});
const processingSteps = ['订单已创建', '已确认收到付款', '汇款处理中', '人民币下发', '到账确认'];
const error = ref('');
const busy = ref(false);
const page = ref(1);
const pages = ref(0);
let active = true;
let awaitingReturn = false;
let returnQueued = false;
let poll: ReturnType<typeof setTimeout> | undefined;
let remainingPolls = 0;

async function fetchOrder(reference: string, queryProvider: boolean): Promise<Order> {
  return queryProvider
    ? request.post<Order>(`${path}/${reference}/refresh`, {}, options)
    : request.get<Order>(`${path}/${reference}`, {}, options);
}

function unsettled(order: Order): boolean {
  return order.can_refresh === true && order.refund_code !== '04'
    && !(order.income_code === '00' && order.outgoing_code === '05' && order.refund_code === '00')
    && !(['05', '06', '07', '08'].includes(order.income_code ?? '') && order.refund_code === '00');
}

function returned() {
  if (!awaitingReturn || !canAutoRefresh()) return;
  if (busy.value) {
    returnQueued = true;
    return;
  }
  returnQueued = false;
  awaitingReturn = false;
  remainingPolls = 2;
  void refreshDetail(true);
}

function canAutoRefresh(): boolean {
  let visible = props.pageVisible;
  // #ifdef H5
  visible = visible && document.visibilityState === 'visible';
  // #endif
  return active && visible;
}

async function refreshDetail(automatic = false) {
  const reference = detail.value?.reference;
  if (!reference) return;
  clearTimeout(poll);
  let succeeded = false;
  await run(async () => {
    const fresh = await fetchOrder(reference, detail.value?.can_refresh === true);
    if (!active || detail.value?.reference !== reference) return;
    detail.value = fresh;
    succeeded = true;
  });
  if (automatic && succeeded && canAutoRefresh() && detail.value && unsettled(detail.value) && remainingPolls > 0) {
    remainingPolls -= 1;
    poll = setTimeout(() => { void refreshDetail(true); }, 12000);
  }
}

function statusTone(order: Order) {
  if (order.status_label === '已下发') return 'tone-success';
  if (/退款|异常|未完成/.test(order.status_label)) return 'tone-warning';
  return 'tone-progress';
}

function isRefund(order: Order): boolean {
  return ['01', '02', '03', '04', '05', '06', '07'].includes(order.refund_code ?? '');
}

async function run(action: () => Promise<void>) {
  if (!active || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (!active) return;
    if (reason instanceof UnauthorizedError && active) emit('expired');
    else error.value = getErrorMessage(reason, '读取订单失败，请稍后重试');
  } finally {
    busy.value = false;
    if (returnQueued && canAutoRefresh()) returned();
  }
}

function load(next: number) {
  const filter = selectedFilter.value;
  const params = filter === 'all' ? { page: next } : { page: next, status: filter };
  return run(async () => {
    const result = await request.get<{ list: Order[]; page: number; total_pages: number; status_filter?: string }>(path, params, options);
    if (!active) return;
    if (filter !== 'all' && result.status_filter !== filter) throw new Error('当前服务暂不支持订单状态筛选');
    items.value = result.list;
    page.value = result.page;
    pages.value = result.total_pages;
  });
}

function selectFilter(filter: OrderFilter) {
  if (busy.value || selectedFilter.value === filter) return;
  selectedFilter.value = filter;
  items.value = [];
  page.value = 1;
  pages.value = 0;
  void load(1);
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
      const fresh = await fetchOrder(reference, detail.value?.can_refresh === true);
      if (!active) return;
      detail.value = fresh;
      if (!fresh.payment_url || !/^https:\/\//.test(fresh.payment_url)) {
        throw new Error('付款链接已不可用，请查看最新订单状态');
      }
      awaitingReturn = true;
      // #ifdef APP-PLUS
      plus.runtime.openURL(fresh.payment_url, () => { awaitingReturn = false; error.value = '无法打开付款页面'; });
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
  showOriginalQuote.value = false;
  showSentResult.value = false;
  clearTimeout(poll);
  awaitingReturn = false;
  returnQueued = false;
  error.value = '';
  emit('detail', false);
  if (!items.value.length) void load(1);
  return true;
}

function reload() {
  const reference = detail.value?.reference ?? (props.detailOnly ? props.initialOrder : undefined);
  return detail.value ? refreshDetail() : reference ? open(reference) : load(page.value);
}

watch(() => props.pageVisible, (visible) => {
  if (visible) returned();
  else clearTimeout(poll);
});
// #ifdef H5
function visibilityChanged() {
  if (document.visibilityState === 'visible') returned();
  else clearTimeout(poll);
}
// #endif
onMounted(async () => {
  // #ifdef H5
  document.addEventListener('visibilitychange', visibilityChanged);
  // #endif
  if (props.initialOrder) {
    await open(props.initialOrder);
    if (canAutoRefresh() && detail.value?.can_refresh) await refreshDetail();
  } else {
    await load(1);
  }
});
onUnmounted(() => {
  active = false;
  returnQueued = false;
  awaitingReturn = false;
  clearTimeout(poll);
  // #ifdef H5
  document.removeEventListener('visibilitychange', visibilityChanged);
  // #endif
});
defineExpose({ back, detailTitle, usesFigmaSurface, hasWhiteHeader, showingSentResult });
</script>

<style scoped>
.order-list {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 26rpx;
  padding-bottom: 20rpx;
}
.order-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 16rpx;
  margin: 20rpx 0 8rpx;
}
.order-filter {
  box-sizing: border-box;
  min-height: 80rpx;
  padding: 0 24rpx;
  border: 2rpx solid #e7e7ef;
  border-radius: 99rpx;
  background: #fff;
  color: #303030;
  font-size: 24rpx;
  font-weight: 600;
  line-height: 76rpx;
}
.order-filter.is-selected {
  border-color: var(--remit-accent);
  background: #eeebff;
  color: var(--remit-accent);
}
.order-filter[disabled] {
  opacity: 0.72;
}
.figma-order-page { padding: 24rpx 32rpx calc(70rpx + env(safe-area-inset-bottom)); }
.order-card {
  display: grid;
  gap: 18rpx;
  width: 100%;
  padding: 32rpx;
  color: #111318;
  text-align: left;
  line-height: 1.45;
}
.order-card-top { align-items: flex-start; }
.order-card-value { align-items: flex-start; min-height: 78rpx; }
.order-card-label { font-weight: 700; }
.order-card-amounts {
  display: grid;
  justify-items: end;
  gap: 2rpx;
  min-width: 0;
}
.order-card-reference { padding-top: 16rpx; border-top: 1rpx solid #e7e7ef; }
.order-card-reference .chevron { flex-shrink: 0; }
.order-meta { color: #6d7180; font-size: 24rpx; overflow-wrap: anywhere; min-width: 0; }
.order-amount { font-size: 40rpx; font-weight: 700; overflow-wrap: anywhere; }
.order-status { padding: 6rpx 14rpx; border-radius: 99rpx; font-size: 22rpx; line-height: 1.4; }
.tone-success { color: #0a7451; background: #e7f7ef; }
.tone-warning { color: #a94722; background: #fff0e6; }
.tone-progress { color: #5243cb; background: #eeebff; }
.order-detail { display: flex; flex: 1; flex-direction: column; gap: 24rpx; }
.order-summary { display: grid; justify-items: center; gap: 14rpx; padding: 10rpx 0; }
.order-summary .order-amount { font-size: 54rpx; }
.order-summary.is-processing {
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: center;
  justify-items: start;
  gap: 4rpx 16rpx;
  padding-bottom: 0;
}
.order-summary.is-processing .order-meta { grid-column: 1 / -1; }
.order-summary.is-processing .order-amount { font-size: 46rpx; }
.progress-hero { gap: 16rpx; padding-top: 44rpx; text-align: center; }
.progress-hero .order-stage-symbol { margin: 0 0 22rpx; }
.progress-hero .heading { font-size: 42rpx; }
.sent-hero { gap: 14rpx; }
.sent-hero .order-amount { font-size: 48rpx; }
.order-stage-symbol {
  display: grid;
  place-items: center;
  width: 240rpx;
  height: 240rpx;
  margin: 30rpx 0 20rpx;
  border-radius: 78rpx;
  background: #ece9ff;
}
.order-stage-symbol image { width: 110rpx; height: 110rpx; }
.order-stage-symbol.is-sent { background: #edf8f2; }
.order-stage-symbol.is-sent .refund-tick {
  width: 31rpx;
  height: 67rpx;
  border-color: #303030;
  border-width: 0 4rpx 4rpx 0;
  border-radius: 0;
}
.payment-check-card { background: #f0eff1; }
.payment-check-card .line > :last-child { font-weight: 500; }
.progress-check-row {
  padding: 0 22rpx;
  color: var(--remit-accent);
  font-size: 24rpx;
}
.progress-notice {
  display: block;
  padding: 24rpx;
  border-radius: 24rpx;
  background: #ededed;
  color: #666;
  font-size: 24rpx;
  line-height: 1.5;
}
.progress-notice.sent-notice { color: #367d5c; background: #edf8f2; }
.sent-status { align-self: flex-start; margin: -8rpx 0 -5rpx; }
.order-breakdown.sent-breakdown { gap: 24rpx; }
.progress-actions .progress-home {
  background: #eeebff;
  border-color: transparent;
  color: var(--remit-accent);
}
.progress-refresh { justify-self: end; }
.order-progress {
  display: grid;
  gap: 0;
  padding: 32rpx;
}
.order-progress-step:last-child { min-height: 60rpx; }
.order-progress-step {
  position: relative;
  display: grid;
  grid-template-columns: 36rpx minmax(0, 1fr);
  align-items: start;
  gap: 28rpx;
  min-height: 130rpx;
  padding-top: 8rpx;
  box-sizing: border-box;
  font-size: 28rpx;
  font-weight: 600;
}
.order-progress-step .order-meta { display: block; margin-top: 12rpx; font-weight: 400; }
.order-progress-step:not(:last-child)::before {
  position: absolute;
  top: 28rpx;
  bottom: -28rpx;
  left: 17rpx;
  width: 2rpx;
  background: #e2e2ea;
  content: "";
}
.order-progress-mark {
  z-index: 1;
  box-sizing: border-box;
  width: 36rpx;
  height: 36rpx;
  border: 4rpx solid #e2e2ea;
  border-radius: 50%;
  background: #fff;
}
.order-progress-mark.is-done { border: 12rpx solid #5eaa80; }
.order-progress-step.is-current .order-progress-mark { border: 12rpx solid var(--remit-accent); }
.progress-help { justify-content: flex-start; padding: 16rpx 20rpx; background: #ededed; }
.progress-help-copy { flex: 1; min-width: 0; }
.progress-help-icon {
  flex: 0 0 76rpx;
  width: 76rpx;
  height: 76rpx;
}
.order-breakdown { display: grid; gap: 20rpx; padding: 32rpx; color: #6d7180; font-size: 24rpx; }
.order-breakdown .line > :first-child { flex-shrink: 0; }
.order-breakdown .line > :last-child {
  min-width: 0;
  color: #111318;
  font-size: 28rpx;
  font-weight: 700;
  text-align: right;
  overflow-wrap: anywhere;
}
.order-total { padding-top: 18rpx; border-top: 1rpx solid #e7e7ef; }
.standard-detail .order-breakdown {
  gap: 30rpx;
  padding: 30rpx 32rpx;
  border-color: #e9e9f0;
  border-radius: 36rpx;
  box-shadow: 0 2rpx 5rpx rgba(31, 35, 48, 0.035);
}
.standard-detail .order-breakdown .line > :last-child { font-size: 34rpx; line-height: 1.24; }
.standard-detail .order-action-pair .primary { box-shadow: 0 10rpx 24rpx rgba(78, 61, 219, 0.21); }
.refund-summary {
  display: grid;
  justify-items: center;
  gap: 20rpx;
  padding: 44rpx 0 24rpx;
  text-align: center;
}
.refund-symbol {
  display: grid;
  place-items: center;
  width: 258rpx;
  height: 258rpx;
  margin-bottom: 42rpx;
  border-radius: 90rpx;
  background: #eeebff;
}
.refund-symbol image { width: 112rpx; height: 112rpx; }
.refund-symbol.completed { background: #e7f7ef; }
.refund-notice { color: #367d5c; background: #edf8f2; }
.refund-notice.is-complete { color: #83502c; background: #fff2e9; }
.refund-tick {
  width: 36rpx;
  height: 80rpx;
  border: solid #111318;
  border-width: 0 11rpx 11rpx 0;
  border-radius: 8rpx;
  transform: translateY(-14rpx) rotate(40deg);
}
.refund-title { color: #111318; font-size: 42rpx; font-weight: 700; }
.order-actions {
  display: grid;
  gap: 14rpx;
  margin-top: auto;
  padding-top: 28rpx;
  padding-bottom: env(safe-area-inset-bottom);
}
.progress-actions {
  gap: 18rpx;
  padding-bottom: calc(4rpx + env(safe-area-inset-bottom));
}
.order-action-pair .primary,
.order-action-pair .secondary {
  flex: 1;
  min-width: 0;
  border-radius: 32rpx;
  font-size: 26rpx;
  font-weight: 700;
}
</style>
