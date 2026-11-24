<template>
  <view class="order-confirmation">
    <view class="body confirmation-content">
      <view class="card confirmation-card">
        <view class="confirmation-amounts">
          <view>
            <text class="muted">汇款本金</text>
            <text class="confirmation-money">€{{ formatAmount(quote.principal_minor) }}</text>
          </view>
          <view class="confirmation-receiving">
            <text class="muted">报价到账金额</text>
            <text class="confirmation-money accent">¥{{ formatAmount(quote.payout_minor) }}</text>
          </view>
        </view>
        <view class="line"><text>报价汇率</text><text>{{ (quote.rate_ten_thousandths / 10000).toFixed(4) }}</text></view>
        <text class="description">{{ quote.eta }}</text>
      </view>

      <view class="card confirmation-card">
        <text class="muted">本人到账账户</text>
        <text class="confirmation-account">{{ accountLabel }}</text>
        <button class="inline-action accent" :disabled="busy || expired" @tap="$emit('account')">更换到账账户</button>
        <view class="line"><text>付款方式</text><text>{{ payments[quote.payment_method] }}</text></view>
        <view class="line"><text>资金来源</text><text>{{ fundSources.find(item => item.value === declaration.source_of_funds)?.label }}</text></view>
        <view class="line"><text>汇款用途</text><text>{{ transferPurposes.find(item => item.value === declaration.purpose)?.label }}</text></view>
        <text v-if="declaration.explanation" class="description">{{ declaration.explanation }}</text>
      </view>

      <view class="card confirmation-card">
        <view class="line"><text>汇款手续费</text><text>€{{ formatAmount(quote.base_fee_minor) }}</text></view>
        <view v-if="quote.discount_minor" class="line"><text>优惠减免</text><text>−€{{ formatAmount(quote.discount_minor) }}</text></view>
        <view class="line"><text>付款通道费</text><text>€{{ formatAmount(quote.channel_fee_minor) }}</text></view>
        <view class="line confirmation-total"><text>合计支付</text><text>€{{ formatAmount(quote.total_payable_minor) }}</text></view>
      </view>
      <text v-if="quote.payment_notice" class="description">{{ quote.payment_notice }}</text>
      <text v-if="quote.expires_at" class="description">
        报价有效至 {{ new Date(quote.expires_at * 1000).toLocaleTimeString() }}
      </text>
    </view>

    <view class="footer confirmation-actions">
      <text v-if="expired" class="hint offer" role="alert">报价已过期，请返回重新报价</text>
      <text v-else-if="error" class="hint offer" role="alert">{{ error }}</text>
      <button class="primary" :disabled="busy || expired" @tap="confirm">{{ busy ? '提交中…' : '确认下单' }}</button>
      <button class="inline-action accent" :disabled="busy" @tap="$emit('edit')">
        {{ expired ? '返回重新报价' : '修改金额或付款方式' }}
      </button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { formatAmount } from './previewOrder';
import { fundSources, transferPurposes, type RemittanceDeclaration } from './remittanceDeclaration';

const props = defineProps<{
  quote: {
    principal_minor: number;
    payout_minor: number;
    rate_ten_thousandths: number;
    payment_method: 'sepa' | 'applePay' | 'card';
    base_fee_minor: number;
    discount_minor: number;
    channel_fee_minor: number;
    total_payable_minor: number;
    eta: string;
    payment_notice: string;
    expires_at?: number;
  };
  accountLabel: string;
  declaration: RemittanceDeclaration;
  busy: boolean;
  error: string;
  pageVisible: boolean;
}>();
const emit = defineEmits<{ confirm: []; edit: []; account: [] }>();
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
.confirmation-content { display: grid; gap: 24rpx; padding-bottom: 24rpx; }
.confirmation-card { display: grid; gap: 20rpx; padding: 28rpx; overflow-wrap: anywhere; }
.confirmation-amounts { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24rpx; }
.confirmation-money { display: block; margin-top: 16rpx; font-size: 40rpx; font-weight: 700; }
.confirmation-receiving { text-align: right; }
.confirmation-account { font-weight: 600; }
.confirmation-total { border-top: 1rpx solid #e1e1e7; padding-top: 24rpx; font-size: 34rpx; font-weight: 700; }
.confirmation-actions { position: sticky; bottom: 0; background: #f2f2f2; }
</style>
