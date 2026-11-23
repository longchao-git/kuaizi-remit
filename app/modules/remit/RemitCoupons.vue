<template>
  <view class="body">
    <view class="line redemption">
      <input v-model="code" maxlength="64" placeholder="输入优惠码" aria-label="优惠码" @confirm="claim" />
      <button class="inline-action accent" :disabled="busy || !code.trim()" @tap="claim">兑换</button>
    </view>
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-else-if="busy" class="description">处理中…</text>
    <text v-else-if="!items.length" class="description">暂无优惠券</text>
    <button class="inline-action accent" :disabled="busy" @tap="$emit('select', null)">不使用优惠券</button>
    <view v-for="item in items" :key="item.id" class="card coupon">
      <text>{{ item.title }}</text>
      <text v-if="item.title !== couponBenefit(item)" class="description">{{ couponBenefit(item) }}</text>
      <text v-if="item.payout_method" class="description">适用{{ item.payout_method === 'wechat' ? '微信' : '银行卡' }}收款</text>
      <text class="description">有效期至 {{ new Date(item.expires_at).toLocaleString() }}</text>
      <button v-if="item.status === 'available'" class="inline-action accent" :disabled="busy" @tap="$emit('select', item)">使用此券</button>
      <button v-else-if="item.status === 'pending_claim'" class="inline-action accent" :disabled="busy" @tap="claimOffer(item.id)">领取优惠券</button>
      <text v-else class="description">{{ labels[item.status] }}</text>
    </view>
    <view v-if="pages > 1" class="line">
      <button class="inline-action" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
      <text>{{ page }} / {{ pages }}</text>
      <button class="inline-action" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
    </view>
    <button v-if="error" class="inline-action accent" :disabled="busy" @tap="load(page)">重新加载</button>
  </view>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import request, { UnauthorizedError } from '@/request/http';
import { couponBenefit, type MemberCoupon } from './memberCoupon';

const emit = defineEmits<{ expired: []; select: [coupon: MemberCoupon | null] }>();
const options = { handleUnauthorized: false };
const busy = ref(false);
const error = ref('');
const code = ref('');
const items = ref<MemberCoupon[]>([]);
const labels: Record<MemberCoupon['status'], string> = {
  available: '可用', pending_claim: '待领取', expired: '已过期', revoked: '已撤销', reserved: '待付款', redeemed: '已核销',
};
const page = ref(1);
const pages = ref(0);

async function query(next: number) {
  const result = await request.get<{ list: MemberCoupon[]; page: number; total_pages: number }>('app/coupons', { page: next }, options);
  items.value = result.list;
  page.value = result.page;
  pages.value = result.total_pages;
}

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (reason instanceof UnauthorizedError) emit('expired');
    error.value = reason instanceof Error ? reason.message : '操作失败，请重试';
  } finally {
    busy.value = false;
  }
}

function load(next: number) {
  return run(() => query(next));
}

function claim() {
  return run(async () => {
    if (!/^[a-z0-9]{20}$/i.test(code.value.trim())) {
      error.value = '请输入 20 位字母或数字优惠码';
      return;
    }
    await request.post('app/coupons/claim', { code: code.value.trim() }, options);
    code.value = '';
    uni.showToast({ title: '已领取优惠券', icon: 'success' });
    await query(1);
  });
}

function claimOffer(id: string) {
  return run(async () => {
    const coupon = await request.post<MemberCoupon>(`app/coupons/${encodeURIComponent(id)}/claim`, {}, options);
    items.value = items.value.map(item => item.id === id ? coupon : item);
    uni.showToast({ title: '已领取优惠券', icon: 'success' });
  });
}

onMounted(() => void load(1));
</script>

<style scoped lang="scss">
.redemption { gap: 16rpx; margin-bottom: 24rpx; }
.redemption input { min-width: 0; flex: 1; padding: 16rpx; border-radius: 16rpx; background: #fff; }
.coupon { display: grid; gap: 12rpx; margin: 16rpx 0; padding: 20rpx; overflow-wrap: anywhere; }
</style>
