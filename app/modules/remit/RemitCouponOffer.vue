<template>
  <view class="sheet-overlay coupon-overlay">
    <view class="sheet-scrim" aria-hidden="true" @tap="$emit('dismiss')" />
    <view class="sheet coupon-sheet" role="dialog" aria-modal="true" aria-label="领取首页优惠券">
      <image class="gift-icon" src="/static/remit/gift.png" mode="aspectFit" />
      <text class="heading offer-title">{{ coupon.title }}</text>
      <text class="discount">{{ couponBenefit(coupon) }}</text>
      <text v-if="coupon.payout_method" class="description">
        适用{{ coupon.payout_method === 'wechat' ? '微信' : '银行卡' }}收款
      </text>
      <text class="description">有效期至 {{ new Date(coupon.expires_at).toLocaleDateString() }}</text>
      <text v-if="error" class="description offer" role="alert">{{ error }}</text>
      <button class="primary" type="default" :disabled="busy" @tap="$emit('claim')">
        {{ busy ? '领取中…' : '立即领取' }}
      </button>
      <button class="inline-action accent" type="default" :disabled="busy" @tap="$emit('dismiss')">稍后领取</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { couponBenefit, type MemberCoupon } from './memberCoupon';

defineProps<{ coupon: MemberCoupon; busy: boolean; error: string }>();
defineEmits<{ claim: []; dismiss: [] }>();
</script>

<style scoped>
.coupon-overlay {
  align-items: center;
  justify-content: center;
  padding: 32rpx;
}

.coupon-sheet {
  display: grid;
  gap: 18rpx;
  justify-items: center;
  max-width: 640rpx;
  padding: 34rpx 28rpx max(26rpx, env(safe-area-inset-bottom));
  border-radius: 36rpx;
  text-align: center;
  overflow-wrap: anywhere;
}

.gift-icon {
  width: 110rpx;
  height: 110rpx;
}

.offer-title {
  margin: 0;
  font-size: 38rpx;
}

.discount {
  color: var(--remit-accent);
  font-weight: 700;
}

.coupon-sheet .primary {
  width: 100%;
  margin-top: 12rpx;
}
</style>
