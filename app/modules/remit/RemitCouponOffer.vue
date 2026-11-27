<template>
  <view v-if="coupon.coupon_type === 'first_order_free'" class="sheet-overlay first-coupon-overlay">
    <view class="first-coupon" role="dialog" aria-modal="true" aria-label="新用户欢迎权益">
      <button class="icon-control first-coupon-close" aria-label="关闭优惠" :disabled="busy" @tap="$emit('dismiss')">
        <image src="/static/remit/close.svg" mode="aspectFit" />
      </button>
      <view class="first-coupon-gift" aria-hidden="true">
        <image src="/static/remit/gift.png" mode="aspectFit" />
      </view>
      <text class="first-coupon-tag">新用户专享</text>
      <text class="first-coupon-title">首笔汇款<br />免基础手续费</text>
      <view class="first-coupon-description">
        <text>领取后可在报价时使用</text>
        <text>到账金额与费用在付款前清楚展示</text>
      </view>
      <view class="card first-coupon-benefit">
        <view class="line"><text>权益</text><text>{{ coupon.title }}</text></view>
        <view v-if="coupon.payout_method" class="line">
          <text>适用渠道</text><text>{{ coupon.payout_method === 'wechat' ? '微信' : '银行卡' }}收款</text>
        </view>
        <view class="line"><text>有效期至</text><text>{{ formatRemitTimestamp(coupon.expires_at, 'date') }}</text></view>
      </view>
      <view class="first-coupon-actions">
        <text v-if="error" role="alert">{{ error }}</text>
        <button class="primary inverted" :disabled="busy" @tap="$emit('claim')">
          {{ busy ? '领取中…' : '立即领取' }}
        </button>
        <text class="first-coupon-note">优惠以实际报价为准</text>
      </view>
    </view>
  </view>
  <view v-else class="sheet-overlay coupon-overlay">
    <view class="sheet-scrim" aria-hidden="true" @tap="$emit('dismiss')" />
    <view class="sheet coupon-sheet" role="dialog" aria-modal="true" aria-label="领取首页优惠券">
      <image class="gift-icon" src="/static/remit/gift.png" mode="aspectFit" />
      <text class="heading offer-title">{{ coupon.title }}</text>
      <text class="discount">{{ couponBenefit(coupon) }}</text>
      <text v-if="coupon.payout_method" class="description">
        适用{{ coupon.payout_method === 'wechat' ? '微信' : '银行卡' }}收款
      </text>
      <text class="description">有效期至 {{ formatRemitTimestamp(coupon.expires_at, 'date') }}</text>
      <text v-if="error" class="description offer" role="alert">{{ error }}</text>
      <button class="primary" type="default" :disabled="busy" @tap="$emit('claim')">
        {{ busy ? '领取中…' : '立即领取' }}
      </button>
      <button class="inline-action accent" type="default" :disabled="busy" @tap="$emit('dismiss')">稍后领取</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { formatRemitTimestamp } from './previewOrder';
import { couponBenefit, type MemberCoupon } from './memberCoupon';

defineProps<{ coupon: MemberCoupon; busy: boolean; error: string }>();
defineEmits<{ claim: []; dismiss: [] }>();
</script>

<style scoped>
.first-coupon-overlay {
  overflow: auto;
  align-items: flex-start;
  background: var(--remit-accent);
  color: #fff;
}
.first-coupon {
  position: relative;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  align-items: center;
  min-height: 100%;
  width: 100%;
  padding: calc(var(--status-bar-height, 0px) + 112rpx) 32rpx max(72rpx, env(safe-area-inset-bottom));
  text-align: center;
}
.first-coupon-close {
  position: absolute;
  top: calc(var(--status-bar-height, 0px) + 24rpx);
  left: 32rpx;
}
.first-coupon-close image {
  width: 60rpx;
  height: 60rpx;
}
.first-coupon-gift {
  display: grid;
  place-items: center;
  width: 282rpx;
  height: 277rpx;
}
.first-coupon-gift image {
  width: 282rpx;
  height: 277rpx;
  transform: scale(1.28);
}
.first-coupon-tag {
  margin-top: 32rpx;
  padding: 8rpx 20rpx;
  border-radius: 90rpx;
  background: #eeebff;
  color: var(--remit-accent);
  font-size: 24rpx;
}
.first-coupon-title {
  margin-top: 32rpx;
  font-size: 66rpx;
  font-weight: 700;
  line-height: 1.42;
}
.first-coupon-description {
  display: grid;
  gap: 4rpx;
  margin-top: 24rpx;
  color: #f2efff;
  font-size: 26rpx;
  line-height: 1.5;
}
.first-coupon-benefit {
  display: grid;
  align-content: center;
  gap: 16rpx;
  box-sizing: border-box;
  width: 100%;
  min-height: 190rpx;
  margin: 32rpx 0 38rpx;
  padding: 32rpx;
  color: #303030;
  overflow-wrap: anywhere;
}
.first-coupon-benefit .line {
  gap: 20rpx;
  text-align: right;
}
.first-coupon-benefit .line > :first-child {
  flex-shrink: 0;
  color: #666;
}
.first-coupon-actions {
  display: grid;
  gap: 16rpx;
  width: 100%;
  margin-top: auto;
}
.first-coupon-actions .primary {
  min-height: 100rpx;
}
.first-coupon-note {
  color: #f2efff;
  font-size: 22rpx;
  line-height: 36rpx;
}
.first-coupon-actions .primary.inverted[disabled] {
  color: var(--remit-accent);
  background: #fff;
}
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
