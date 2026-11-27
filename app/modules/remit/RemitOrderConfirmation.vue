<template>
  <view class="order-confirmation">
    <view class="body confirmation-content">
      <view class="card confirmation-card confirmation-quote-card">
        <text class="confirmation-caption">汇款</text>
        <view class="confirmation-amounts">
          <text class="confirmation-money">€{{ formatAmount(quote.principal_minor) }}</text>
          <text class="confirmation-arrow" aria-hidden="true">→</text>
          <text class="confirmation-money confirmation-receiving accent" :aria-label="'报价到账金额 ' + formatAmount(quote.payout_minor) + ' 人民币'">
            ¥{{ formatAmount(quote.payout_minor) }}
          </text>
        </view>
        <view class="line confirmation-rate">
          <text>报价汇率 {{ (quote.rate_ten_thousandths / 10000).toFixed(4) }}</text>
          <text>{{ quote.eta }}</text>
        </view>
      </view>

      <view class="line card confirmation-account-row">
        <view class="confirmation-row-icon" aria-hidden="true">
          <text v-if="quote.payout_method === 'wechat'">微</text>
          <image v-else src="/static/remit/bank-card.svg" mode="aspectFit" />
        </view>
        <view class="confirmation-row-content">
          <text class="confirmation-account">到账：{{ accountLabel }}</text>
          <text class="confirmation-caption">本人已核验账户</text>
        </view>
        <button class="inline-action accent" :disabled="busy || expired" @tap="$emit('account')">更换</button>
      </view>
      <view class="line card confirmation-account-row">
        <text class="confirmation-row-icon" aria-hidden="true">€</text>
        <view class="confirmation-row-content">
          <text>付款：{{ payments[quote.payment_method] }}</text>
          <text class="confirmation-caption">付款通道费 €{{ formatAmount(quote.channel_fee_minor) }}</text>
        </view>
        <button class="inline-action accent" :disabled="busy" @tap="$emit('payment')">更换</button>
      </view>

      <view class="card confirmation-card">
        <view class="line"><text>汇款手续费</text><text>€{{ formatAmount(quote.base_fee_minor) }}</text></view>
        <view v-if="quote.discount_minor" class="line"><text>优惠减免</text><text>−€{{ formatAmount(quote.discount_minor) }}</text></view>
        <view class="line"><text>付款通道费</text><text>€{{ formatAmount(quote.channel_fee_minor) }}</text></view>
        <view class="line confirmation-total"><text>合计支付</text><text>€{{ formatAmount(quote.total_payable_minor) }}</text></view>
      </view>
      <view class="confirmation-notice">
        <text>资金来源 {{ fundSources.find(item => item.value === declaration.source_of_funds)?.label }}</text>
        <text>汇款用途 {{ transferPurposes.find(item => item.value === declaration.purpose)?.label }}</text>
        <text v-if="declaration.explanation">{{ declaration.explanation }}</text>
        <text v-if="quote.payment_notice">{{ quote.payment_notice }}</text>
        <text v-if="quote.simulated">本地模拟订单，不会扣款。</text>
        <text v-if="quote.expires_at">
          报价有效至 {{ formatRemitTimestamp(quote.expires_at * 1000, 'time') }}
        </text>
      </view>
    </view>

    <view class="footer confirmation-actions">
      <text v-if="expired" class="hint offer" role="alert">报价已过期，请返回重新报价</text>
      <text v-else-if="error" class="hint offer" role="alert">{{ error }}</text>
      <button v-if="expired" class="primary" :disabled="busy" @tap="$emit('edit')">返回重新报价</button>
      <button v-else class="primary" :disabled="busy" @tap="confirm">{{ busy ? '提交中…' : '确认下单' }}</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { formatAmount, formatRemitTimestamp } from './previewOrder';
import { fundSources, transferPurposes, type RemittanceDeclaration } from './remittanceDeclaration';

const props = defineProps<{
  quote: {
    principal_minor: number;
    payout_minor: number;
    rate_ten_thousandths: number;
    payment_method: 'sepa' | 'applePay' | 'card';
    payout_method: 'bank' | 'wechat';
    base_fee_minor: number;
    discount_minor: number;
    channel_fee_minor: number;
    total_payable_minor: number;
    eta: string;
    payment_notice: string;
    expires_at?: number;
    simulated?: boolean;
  };
  accountLabel: string;
  declaration: RemittanceDeclaration;
  busy: boolean;
  error: string;
  pageVisible: boolean;
}>();
const emit = defineEmits<{ confirm: []; edit: []; account: []; payment: [] }>();
const payments = { sepa: 'SEPA 银行转账', applePay: 'Apple Pay', card: '银行卡付款' };
const clock = ref(Date.now());
const expired = computed(() => !props.quote.expires_at || clock.value >= props.quote.expires_at * 1000);
let timer: ReturnType<typeof setInterval> | undefined;

function confirm() {
  clock.value = Date.now();
  if (!props.busy && !expired.value) {
    emit('confirm');
  }
}

watch(() => props.pageVisible, () => { clock.value = Date.now(); });
onMounted(() => {
  timer = setInterval(() => { clock.value = Date.now(); }, 1000);
});
onUnmounted(() => { clearInterval(timer); });
</script>

<style scoped>
.order-confirmation { flex: 1; display: flex; flex-direction: column; }
.confirmation-content { display: grid; gap: 20rpx; padding: 32rpx 32rpx 24rpx; }
.confirmation-card { display: grid; gap: 22rpx; padding: 28rpx; overflow-wrap: anywhere; }
.confirmation-quote-card { min-height: 283rpx; gap: 18rpx; align-content: center; }
.confirmation-caption { display: block; color: #777; font-size: 24rpx; }
.confirmation-amounts {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr);
  align-items: center;
  gap: 16rpx;
}
.confirmation-money { display: block; font-size: 44rpx; font-weight: 700; overflow-wrap: anywhere; }
.confirmation-arrow { color: #303030; font-size: 56rpx; line-height: 1; }
.confirmation-receiving { text-align: right; }
.confirmation-rate {
  box-sizing: border-box;
  min-height: 82rpx;
  padding: 16rpx 22rpx;
  border-radius: 22rpx;
  color: var(--remit-accent);
  background: #f8f7ff;
  font-size: 24rpx;
}
.confirmation-account-row { min-height: 128rpx; align-items: center; padding: 20rpx 24rpx; }
.confirmation-row-icon {
  display: grid;
  place-items: center;
  flex: 0 0 82rpx;
  height: 82rpx;
  border-radius: 26rpx;
  background: #eeebff;
  color: var(--remit-accent);
  font-size: 36rpx;
  font-weight: 700;
}
.confirmation-row-icon image { width: 42rpx; height: 42rpx; }
.confirmation-row-content { display: grid; flex: 1; min-width: 0; gap: 8rpx; overflow-wrap: anywhere; }
.confirmation-account-row button { flex-shrink: 0; }
.confirmation-total { border-top: 1rpx solid #e1e1e7; padding-top: 24rpx; }
.confirmation-total > :last-child { font-size: 36rpx; font-weight: 700; }
.confirmation-notice {
  display: flex;
  flex-wrap: wrap;
  gap: 8rpx 22rpx;
  padding: 18rpx 22rpx;
  border-radius: 24rpx;
  background: #edf8f2;
  color: #367d5c;
  font-size: 22rpx;
}
.confirmation-actions {
  position: sticky;
  bottom: 0;
  padding-bottom: max(72rpx, env(safe-area-inset-bottom));
  background: var(--remit-light-surface);
}
</style>
