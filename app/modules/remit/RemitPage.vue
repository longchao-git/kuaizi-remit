<template>
  <RemitLocalSession
    v-if="localSessionOpen"
    ref="localSession"
    :page-visible="pageVisible"
    :allow-local-login="localLoginEnabled"
    @back="closeLocalSession"
  />
  <view
    v-else
    class="remit"
    :data-screen="screen"
    :class="{ purple: screen === 1 || screen === 5, capturing: screen >= 14 && screen <= 16 }"
  >
    <view v-if="screen === 1" class="splash" @tap="go(2)">
      <view class="brand splash-brand">
        <image class="splash-mark" src="/static/remit/logo-splash.svg" mode="aspectFit" />
        <view><text class="brand-name">筷子闪汇</text><text class="brand-en">KUAIZI REMIT</text></view>
      </view>
      <view class="splash-caption">
        <text>钱到得快，更到得稳</text>
        <text class="subtle">欧洲至中国 · 合规跨境汇款服务</text>
      </view>
    </view>

    <template v-else-if="screen === 5">
      <view class="welcome-body">
        <button role="button" tabindex="0" class="close icon-control" aria-label="关闭优惠" @tap="closeOffer">
          <image class="round-icon" src="/static/remit/close.svg" mode="aspectFit" />
        </button>
        <view class="gift"><image src="/static/remit/gift.png" mode="scaleToFill" /></view>
        <text class="gift-label">新用户专享</text>
        <text class="gift-title">首笔汇款<br />0 手续费</text>
        <text class="gift-copy">优惠将在报价页自动使用<br />到账金额与费用在付款前清楚展示</text>
        <view class="card gift-prices">
          <view class="line"><text>标准手续费</text><text class="strike">€5.99</text></view>
          <view class="line"><text>新客优惠后</text><text class="accent price">€0.00</text></view>
        </view>
      </view>
      <view class="footer"
        ><button role="button" tabindex="0" class="primary inverted" @tap="go(6)">开始汇款</button
        ><text class="hint light">优惠规则与适用范围以页面说明为准</text></view
      >
    </template>

    <template v-else-if="screen === 6">
      <view class="home-content">
        <view class="line home-head">
          <view class="brand">
            <view class="brand-tile"><image src="/static/remit/logo-home.svg" mode="aspectFit" /></view>
            <view><text class="brand-name">筷子闪汇</text><text class="brand-en">KUAIZI REMIT</text></view>
          </view>
          <button role="button" tabindex="0" class="support icon-control" aria-label="帮助与客服" @tap="go(35)">
            <image src="/static/remit/support.svg" mode="aspectFit" />
          </button>
        </view>
        <view class="promotion">🔥 周末手续费半价：标准 €5.99，今日 €2.99</view>
        <view class="card quote-card">
          <view class="line quote-label"><text>汇款金额</text><text class="pill">EUR → CNY</text></view>
          <view class="currency">
            <view class="flag eu"><image src="/static/remit/eu.png" mode="scaleToFill" /></view><text>EUR</text>
            <input v-model="amount" type="digit" maxlength="12" class="currency-amount" aria-label="汇款金额" />
          </view>
          <view class="line quote-label"><text>收款金额</text><text class="muted">预计2~3分钟到账</text></view>
          <view class="currency">
            <image class="flag" src="/static/remit/china.svg" mode="aspectFit" /><text>CNY</text>
            <text class="currency-amount accent" :class="{ 'wide-amount': received.length >= 11 }">{{
              received
            }}</text>
          </view>
          <view class="line payout"
            ><view
              ><text>{{ payout }}</text
              ><text class="muted block">已实名 · 默认到账方式</text></view
            ><button role="button" tabindex="0" class="inline-action accent" @tap="openPayoutPicker">
              更换
            </button></view
          >
        </view>
        <view class="quote-breakdown">
          <view class="line"
            ><text>筷子闪汇优惠汇率</text><text>{{ (previewRate / 10000).toFixed(4) }}</text></view
          >
          <view class="line muted reference"
            ><text>中国银行现汇买入参考</text><text class="strike">8.0120</text></view
          >
          <view class="line breakdown-divider"
            ><text>汇款手续费</text><text>€{{ fee }}</text></view
          >
          <view class="line"
            ><text>优惠券：</text
            ><button
              role="button"
              tabindex="0"
              class="inline inline-action"
              @tap="chooseCoupon"
            >
              <text :class="{ offer: coupon !== 'none' }">{{ couponLabel }}</text
              ><image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" /></button
          ></view>
        </view>
      </view>
      <view class="home-action line"
        ><view class="home-savings"
          ><text class="muted block">为你节省</text
          ><text class="price">¥{{ formatAmount(quote.savings) }}</text></view
        ><button
          role="button"
          :tabindex="!canStartRemit ? -1 : 0"
          class="primary"
          :disabled="!canStartRemit"
          @tap="go(8)"
        >
          开始汇款
        </button></view
      >
    </template>

    <template v-else>
      <view class="top">
        <button role="button" tabindex="0" v-if="!isTab" class="back-hit" aria-label="返回" @tap="back">
          <text v-if="screen >= 14 && screen <= 16">×</text>
          <image v-else class="round-icon" src="/static/remit/back.svg" mode="aspectFit" />
        </button>
        <text>{{ currentTitle }}</text>
        <button
          role="button"
          tabindex="0"
          v-if="screen === 34"
          class="top-action"
          @tap="markRead(noticeList.map((item) => item.id))"
        >
          全部已读
        </button>
      </view>
      <template v-if="screen === 2">
        <view class="body auth">
          <view class="brand">
            <view class="brand-tile login-tile"
              ><image src="/static/remit/logo-login.svg" mode="aspectFit"
            /></view>
            <view><text class="brand-name">筷子闪汇</text><text class="brand-en">KUAIZI REMIT</text></view>
          </view>
          <text class="heading">欢迎使用筷子闪汇</text>
          <text class="description">输入西班牙手机号，登录或创建您的账户</text>
          <view class="phone card">
            <button
              role="button"
              tabindex="0"
              class="country-trigger inline"
              aria-label="选择国家"
              @tap="openCountryPicker"
            >
              <view class="spain-flag" /><text>+34</text>
            </button>
            <input v-model="mobile" type="number" maxlength="9" placeholder="请输入手机号" aria-label="手机号" />
          </view>
          <view class="agreement">
            <button
              tabindex="0"
              class="check-hit icon-control"
              role="checkbox"
              aria-label="同意协议"
              :aria-checked="accepted"
              @tap="accepted = !accepted"
            >
              <view class="agreement-check" :class="{ 'is-checked': accepted }" aria-hidden="true">
                <view v-if="accepted" class="agreement-tick" />
              </view>
            </button>
            <text
              >我已阅读并同意
              <text class="accent" role="link" tabindex="0" @tap="showAgreement('terms')">《用户协议》</text>
              <text class="accent" role="link" tabindex="0" @tap="showAgreement('privacy')">《隐私政策》</text>及
              <text class="accent" role="link" tabindex="0" @tap="showAgreement('rules')">《汇款服务规则》</text></text
            >
          </view>
        </view>
        <view class="footer">
          <text v-if="loginError" class="hint offer" role="alert">{{ loginError }}</text>
          <button role="button" tabindex="0" class="primary" :disabled="smsBusy" @tap="requestCode">
            {{ smsBusy ? "请稍候…" : "获取验证码" }}
          </button>
          <text v-if="demoEnabled" class="hint">演示流程 · 不会发送短信或创建账号</text>
          <text v-else-if="smsLoginEnabled" class="hint">本地联调使用固定验证码</text>
          <button v-if="localLoginEnabled" class="text-button" @tap="localSessionOpen = true">使用测试账号</button>
        </view>
      </template>
      <template v-else-if="screen === 3">
        <view class="body otp">
          <view class="mail"><image src="/static/remit/mail.svg" mode="aspectFit" /></view>
          <text class="heading">输入验证码</text>
          <text class="description">验证手机号 +34 {{ maskedMobile }}</text>
          <view class="otp-field">
            <view v-for="n in 6" :key="n" class="digit" :class="{ current: n === Math.min(code.length + 1, 6) }">{{
              code[n - 1]
            }}</view>
            <input v-model="code" type="number" maxlength="6" aria-label="短信验证码" @blur="syncCodeOnBlur" />
          </view>
          <button
            role="button"
            :tabindex="remaining > 0 || smsBusy ? -1 : 0"
            class="text-button"
            :disabled="remaining > 0 || smsBusy"
            @tap="requestCode"
          >
            {{ remaining > 0 ? remaining + " 秒后可重新发送" : "重新发送验证码" }}
          </button>
          <text v-if="demoEnabled" class="hint">演示验证码 {{ demoCode }}</text>
          <text v-else-if="smsLoginEnabled" class="hint">本地测试验证码 123456</text>
        </view>
        <view class="footer"
          ><text v-if="codeError" class="hint offer" role="alert">{{ codeError }}</text>
          <button
            role="button"
            :tabindex="!validCode || smsBusy ? -1 : 0"
            class="primary"
            :disabled="!validCode || smsBusy"
            @tap="verifyCode"
          >
            {{ smsBusy ? "请稍候…" : "登录 / 注册" }}</button
          ><text v-if="demoEnabled" class="hint accent" role="link" tabindex="0" @tap="go(36)">收不到验证码？ 联系客服</text></view
        >
      </template>
      <template v-else-if="screen === 4">
        <view class="body notices">
          <text class="heading">汇款服务重要提示</text>
          <text class="description">为保护您的资金和账户安全，请确认以下事项</text>
          <view class="card notice-card">
            <view v-for="notice in notices" :key="notice" class="notice"
              ><view class="agreement-check is-checked" aria-hidden="true"><view class="agreement-tick" /></view>
              <text>{{ notice }}</text></view
            >
          </view>
          <text class="description legal-copy"
            >筷子闪汇提供信息展示、客户支持及交易流程协助；具体汇款与结算由持牌合作机构执行。页面展示的到账时间为预计时间，受银行、合规审查及收款行处理影响。</text
          >
        </view>
        <view class="footer"
          ><button role="button" tabindex="0" class="primary" @tap="confirmNotices">
            {{ noticeOnboarding ? "同意并继续" : "返回" }}</button
          ><button role="button" tabindex="0" class="secondary" @tap="showAgreement('rules')">
            查看服务说明
          </button></view
        >
      </template>
      <RemitPrototype
        v-else-if="screens[screen]"
        :screen="screen"
        :amount="amount"
        :received="received"
        :payout="screen === 8 ? pendingPayout : payout"
        :accounts="accounts"
        :phone="maskedMobile"
        :fee="fee"
        :aio="false"
        :rate="previewRate"
        :order-id="orderId || draftId"
        :orders="orderList"
        :order="activeOrder"
        :account-settings-mode="payoutPickerMode"
        :read-notifications="readNotifications"
        :messages="messages"
        :server-messages="supportMessages"
        :server-chat="supportConnected"
        :server-chat-live="supportLive"
        :server-chat-status="supportStatus"
        :server-chat-sending="supportSending"
        :document-type="documentType"
        :payment="payment"
        :identity-settings="identitySettings"
        :notifications="noticeList"
        :wechat-bound="wechatBound"
        @navigate="go"
        @manage-coupon="chooseCoupon"
        @back="back"
        @select-payout="pendingPayout = $event"
        @confirm-payout="confirmPayout"
        @add-account="addAccount"
        @open-order="openOrder"
        @advance-order="advanceOrder"
        @order-information="setOrderInformation"
        @read-notifications="markRead"
        @send-message="sendPreviewMessage"
        @refresh-chat="refreshSupportMessages()"
        @logout="logoutRemit"
        @select-document="documentType = $event"
        @select-payment="payment = $event"
        @verify-identity="approveIdentity"
        @open-identity="openIdentityResult"
        @start-identity-review="startIdentityReview"
        @preview-wechat-binding="previewWechatBinding"
        @preview-apple-pay="previewApplePay"
        @legal="showAgreement"
      />
    </template>
    <view v-if="isTab" class="tabs">
      <button
        role="button"
        tabindex="0"
        v-for="tab in tabs"
        :key="tab.screen"
        class="tab"
        :class="{ active: tab.screen === screen }"
        @tap="go(tab.screen)"
      >
        <image :src="tab.icon" mode="aspectFit" /><text>{{ tab.label }}</text>
      </button>
    </view>
    <view v-if="countryOpen" class="sheet-overlay">
      <view class="sheet-scrim" aria-hidden="true" @tap="closeSheet" />
      <view v-if="countryOpen" class="sheet country-sheet" role="dialog" aria-modal="true" aria-label="选择国家">
        <view class="top">
          <button
            role="button"
            tabindex="0"
            class="back-hit sheet-back"
            aria-label="关闭国家选择"
            @tap="closeSheet"
          >
            ‹
          </button>
          <text>选择国家</text>
        </view>
        <input
          v-model="countrySearch"
          class="country-search"
          placeholder="搜索国家或区号"
          aria-label="搜索国家或区号"
          maxlength="30"
        />
        <button v-if="showsSpain" role="button" tabindex="0" class="country-choice inline" @tap="closeSheet">
          <text>西班牙</text><view class="spain-flag" /><text>+34</text>
        </button>
        <text v-else class="country-search">没有匹配的可用国家或区号</text>
      </view>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from "vue";
import { onBackPress, onHide, onShow } from "@dcloudio/uni-app";
import { AuthRepository } from "@/modules/auth/repository/AuthRepository";
import { UnauthorizedError } from "@/request/http";
import { sessionService } from "@/shared/session/application/SessionService";
import { getErrorMessage } from "@/shared/utils/error";
import RemitPrototype from "./RemitPrototype.vue";
import RemitLocalSession from "./RemitLocalSession.vue";
import { screens } from "./prototypeScreens";
import { createIdentityNotification, createOrderNotification, notifications, orders } from "./previewContent";
import {
  discountedFee,
  formatAmount,
  getPreviewChannelFee,
  getQuote,
  localDateKey,
  nextStages,
  orderDestination,
  previewRate,
} from "./previewOrder";
import type { OrderStage, PreviewOrder } from "./previewOrder";
import { useRemitKeyboard } from "./useRemitKeyboard";
import { useSupportChat } from "./useSupportChat";

const titles: Record<number, string> = { 2: "登录 / 注册", 3: "验证手机号", 4: "使用前请确认" };
const notices = [
  "仅使用本人名下银行账户付款",
  "汇款用于真实、合法的个人用途",
  "人民币仅汇入本人实名到账账户",
  "理解汇率锁定时效及退款规则",
];
const tabs = [
  { screen: 6, label: "首页", icon: "/static/remit/tab-home.svg" },
  { screen: 32, label: "订单", icon: "/static/remit/tab-orders.svg" },
  { screen: 37, label: "我的", icon: "/static/remit/tab-profile.svg" },
];

const screen = ref(1);
const localLoginEnabled = import.meta.env.DEV && import.meta.env.VITE_REMIT_LOCAL_LOGIN === 'true';
const smsLoginEnabled = import.meta.env.DEV && import.meta.env.VITE_REMIT_LOCAL_SMS_LOGIN !== "false";
const demoEnabled = !smsLoginEnabled && (import.meta.env.DEV || import.meta.env.VITE_REMIT_DEMO === "true");
const localSessionOpen = ref((localLoginEnabled || smsLoginEnabled) && sessionService.isLoggedIn());
const localSession = ref<InstanceType<typeof RemitLocalSession>>();
const pageVisible = ref(true);
const memberAuth = new AuthRepository();
const smsBusy = ref(false);
let pendingLogin: Awaited<ReturnType<AuthRepository["loginRemitSms"]>> | undefined;
let loggingOut = false;
const {
  connected: supportConnected,
  messages: supportMessages,
  live: supportLive,
  status: supportStatus,
  sending: supportSending,
  send: sendSupportMessage,
  refresh: refreshSupportMessages,
} = useSupportChat(() => pageVisible.value && screen.value === 36);
interface PreviewLocation {
  screen: number;
  orderId: string;
}
const history: PreviewLocation[] = [];
const mobile = ref("");
const code = ref("");
const demoCode = "805426";
let issuedFor = "";
const accepted = ref(false);
const loginError = ref("");
const codeError = ref("");
watch([mobile, accepted], () => {
  loginError.value = "";
});
watch(code, () => {
  codeError.value = "";
});
const countryOpen = ref(false);
const countrySearch = ref("");
const showsSpain = computed(() => {
  const query = countrySearch.value.trim().toLowerCase().replace(/\s+/g, "");
  return ["西班牙", "spain", "españa", "+34"].some((label) => label.includes(query));
});
const amount = ref("100");
const payout = ref("中国银行 · 2198");
const wechatBound = ref(false);
const pendingPayout = ref(payout.value);
const payoutPickerMode = ref(false);
const initialAccounts = ["微信收款", "中国银行 · 2198", "招商银行 · 6331", "支付宝"];
const accounts = ref([...initialAccounts]);
const coupon = ref<"first" | "code" | "none">("first");
const usedCoupons = ref(new Set<"first" | "code">());
const couponLabel = computed(() => ({
  first: "新用户优惠券",
  code: "演示手续费半价券",
  none: "选择优惠券",
})[coupon.value]);
const remaining = ref(0);
const orderId = ref("");
const orderList = ref(orders.map((order) => ({ ...order })));
const draftId = computed(() => "KZ" + (829105 + orderList.value.length - orders.length));
const activeOrder = computed(() => orderList.value.find((order) => order.id === orderId.value));
const messageThreads = ref<Record<string, string[]>>({});
const noticeList = ref([...notifications]);
const readNotifications = ref<string[]>([]);
const documentType = ref(0);
const identityApproved = ref(false);
const identityPending = ref(false);
const identitySettings = ref(false);
const payment = ref(0);
const purpose = ref("");
const source = ref("");
const chatKey = computed(() => orderId.value || "support");
const messages = computed(() => messageThreads.value[chatKey.value] ?? []);
const currentTitle = computed(() => {
  if (screen.value === 29) return "补件进度";
  if (screen.value === 22) return payment.value === 1 ? "确认 Apple Pay 付款" : "确认银行卡付款";
  return titles[screen.value] || screens[screen.value]?.title;
});
const isTab = computed(() => tabs.some((tab) => tab.screen === screen.value));
const noticeOnboarding = computed(() => screen.value === 4 && history[history.length - 1]?.screen === 3);
const quote = computed(() => {
  const appliedFee = { first: 0, code: Math.round(discountedFee / 2), none: discountedFee };
  return getQuote(amount.value, appliedFee[coupon.value], false);
});
const fee = computed(() => formatAmount(quote.value.fee));
const canStartRemit = computed(() => quote.value.canStart);
const validCode = computed(() => /^\d{6}$/.test(code.value));
const received = computed(() => formatAmount(quote.value.received));
const maskedMobile = computed(() =>
  mobile.value ? mobile.value[0] + "•• ••• ••" + mobile.value.slice(-1) : "***",
);
let splashTimer: ReturnType<typeof setTimeout>;
let smsTimer: ReturnType<typeof setInterval> | undefined;
let resendAt = 0;

function go(next: number) {
  const previous = screen.value;
  if (next === 7 || next === 25) return;
  if (next === 8 && previous === 39) {
    back();
    return;
  }
  if (next === 12) identitySettings.value = previous === 37;
  if (next === 12 && identityApproved.value) next = previous === 37 ? 18 : 20;
  else if (next === 12 && identityPending.value) next = 17;
  if (next === screen.value) return;
  if (next === 10 && !canStartRemit.value) {
    uni.showToast({ title: "请输入有效汇款金额，最低 €100", icon: "none" });
    return;
  }
  if (next === 8) {
    pendingPayout.value = payout.value;
    payoutPickerMode.value = previous === 37;
  }
  if (next !== 2 && next === history[history.length - 1]?.screen) {
    back();
    return;
  }
  if (next === 2 || tabs.some((tab) => tab.screen === next)) {
    history.length = 0;
    orderId.value = "";
  } else {
    history.push({ screen: previous, orderId: orderId.value });
  }
  if (next === 10) {
    orderId.value = "";
    purpose.value = "";
    source.value = "";
  }
  if (next === 20 && previous !== 21 && previous !== 22) payment.value = 0;
  if (next === 2) {
    pendingLogin = undefined;
    mobile.value = "";
    code.value = "";
    loginError.value = "";
    codeError.value = "";
    issuedFor = "";
    accepted.value = false;
    remaining.value = 0;
    resendAt = 0;
    amount.value = "100";
    payout.value = initialAccounts[1]!;
    wechatBound.value = false;
    accounts.value = [...initialAccounts];
    coupon.value = "first";
    usedCoupons.value.clear();
    orderId.value = "";
    orderList.value = orders.map((order) => ({ ...order }));
    noticeList.value = [...notifications];
    readNotifications.value = [];
    messageThreads.value = {};
    identityApproved.value = false;
    identityPending.value = false;
    documentType.value = 0;
    identitySettings.value = false;
    payment.value = 0;
    if (smsTimer) clearInterval(smsTimer);
  }
  setScreen(next);
}

async function logoutRemit() {
  if (loggingOut) return;
  const token = sessionService.getToken();
  loggingOut = true;
  try {
    if (token) await memberAuth.logout();
  } catch (error) {
    if (!(error instanceof UnauthorizedError)) {
      uni.showToast({ title: "退出失败，请重试", icon: "none" });
      return;
    }
  } finally {
    loggingOut = false;
  }

  if (!sessionService.getToken() || sessionService.getToken() === token) {
    sessionService.clearAuthSession();
    go(2);
  }
}

function setScreen(next: number) {
  clearTimeout(splashTimer);
  screen.value = next;
  if (next === 1) scheduleSplash();
  void nextTick(() => uni.pageScrollTo({ scrollTop: 0, duration: 0 }));
}

function back() {
  if (screen.value === 4) pendingLogin = undefined;
  const previous = history.pop();
  const order = activeOrder.value;
  if (previous && order && previous.orderId === order.id) {
    const previousStep = previous.screen;
    if (
      previousStep === 21 ||
      previousStep === 22 ||
      (previousStep === 28 && order.stage !== 27) ||
      (nextStages[previousStep as OrderStage] && previousStep !== order.stage)
    ) {
      go(32);
      return;
    }
  }
  if (previous) orderId.value = previous.orderId;
  setScreen(previous?.screen ?? (screen.value <= 2 ? 1 : 6));
}

function closeSheet() {
  if (countryOpen.value) countryOpen.value = false;
  else back();
}

function openCountryPicker() {
  countrySearch.value = "";
  countryOpen.value = true;
}

function chooseCoupon() {
  const options: Array<{ label: string; select: () => void }> = [
    { label: "不使用优惠券", select: () => { coupon.value = "none"; } },
  ];
  if (!usedCoupons.value.has("first")) {
    options.unshift({ label: "使用新用户优惠券", select: () => { coupon.value = "first"; } });
  }
  if (!usedCoupons.value.has("code")) {
    options.push({ label: "输入演示优惠码", select: redeemCoupon });
  }
  uni.showActionSheet({
    itemList: options.map((option) => option.label),
    success: ({ tapIndex }) => options[tapIndex]?.select(),
  });
}

function redeemCoupon() {
  uni.showModal({
    title: "兑换优惠券",
    editable: true,
    placeholderText: "演示优惠码 REMIT-DEMO",
    success: (result) => {
      if (!result.confirm) return;
      if (usedCoupons.value.has("code") || result.content?.trim().toUpperCase() !== "REMIT-DEMO") {
        uni.showToast({ title: "优惠码无效或已使用", icon: "none" });
        return;
      }
      coupon.value = "code";
      uni.showToast({ title: "已应用演示半价券", icon: "none" });
    },
  });
}

useRemitKeyboard(() => (countryOpen.value ? "country" : undefined), closeSheet);

function closeOffer() {
  if (history[history.length - 1]?.screen === 37) back();
  else go(6);
}

function openOrder(id: string) {
  const order = orderList.value.find((item) => item.id === id);
  if (!order) return;
  go(orderDestination(order));
  orderId.value = id;
}

function openPayoutPicker() {
  go(8);
  payoutPickerMode.value = true;
}

function confirmPayout() {
  if (pendingPayout.value === "微信收款" && !wechatBound.value) {
    go(39);
    return;
  }
  if (!payoutPickerMode.value && !canStartRemit.value) {
    go(10);
    return;
  }
  payout.value = pendingPayout.value;
  if (payoutPickerMode.value) back();
  else go(10);
}

function previewWechatBinding() {
  uni.showModal({
    title: "预览微信绑定结果",
    content: "请选择本次模拟绑定结果。",
    confirmText: "绑定成功",
    cancelText: "绑定失败",
    success: (result) => {
      if (screen.value !== 39) return;
      if (!result.confirm) {
        uni.showToast({ title: "绑定未完成，可以重试", icon: "none" });
        return;
      }
      wechatBound.value = true;
      back();
    },
  });
}

function addAccount(account: string) {
  if (!accounts.value.includes(account)) accounts.value.splice(accounts.value.length - 1, 0, account);
  pendingPayout.value = account;
}

function setOrderInformation(purposeValue: string, sourceValue: string) {
  purpose.value = purposeValue;
  source.value = sourceValue;
}

function markRead(ids: string[]) {
  readNotifications.value = [...new Set([...readNotifications.value, ...ids])];
}

function sendPreviewMessage(message: string, onSent?: () => void) {
  if (supportConnected.value) {
    void sendSupportMessage(message).then((sent) => { if (sent) onSent?.(); });
    return;
  }
  messageThreads.value[chatKey.value] = [...messages.value, message];
}

function approveIdentity() {
  if (!identityApproved.value) {
    noticeList.value.unshift(createIdentityNotification());
  }
  identityApproved.value = true;
  identityPending.value = false;
  go(18);
}

function openIdentityResult() {
  identitySettings.value = true;
  go(18);
}

function startIdentityReview() {
  identityPending.value = true;
  go(17);
}

function previewApplePay() {
  uni.showModal({
    title: "Apple Pay 付款演示",
    content: "本次应付 €" + formatAmount(quote.value.subtotal + getPreviewChannelFee(quote.value.cents, 1)),
    confirmText: "模拟付款",
    success: (result) => {
      if (result.confirm && screen.value === 20 && payment.value === 1) advanceOrder(23, 1);
    },
  });
}

function advanceOrder(next: OrderStage, channel: number, attachment?: string) {
  const paymentScreen = screen.value === 21 || screen.value === 22 || (screen.value === 20 && channel === 1);
  if (paymentScreen && next === 23 && !activeOrder.value) {
    const snapshot = quote.value;
    if (!snapshot.canStart) return;
    const order: PreviewOrder = {
      id: draftId.value,
      date: localDateKey(),
      stage: 23,
      account: payout.value,
      amount: snapshot.cents,
      received: snapshot.received,
      fee: snapshot.fee,
      rate: previewRate,
      total: snapshot.subtotal + getPreviewChannelFee(snapshot.cents, channel),
      aio: false,
      channel,
      purpose: purpose.value,
      source: source.value,
    };
    orderList.value.unshift(order);
    if (coupon.value !== "none") usedCoupons.value.add(coupon.value);
    coupon.value = "none";
    orderId.value = order.id;
    go(23);
    return;
  }
  const order = activeOrder.value;
  if (!order) return;
  if (screen.value === 28 && (order.stage !== 27 || next !== 29 || !attachment)) return;
  if (!nextStages[order.stage]?.includes(next)) {
    uni.showToast({ title: "订单状态已更新", icon: "none" });
    go(orderDestination(order));
    return;
  }
  order.stage = next;
  if (attachment) order.attachment = attachment;
  if (next === 26) order.completedDate = localDateKey();
  const notice = next === 29 && !attachment ? undefined : createOrderNotification(order.id, next);
  if (notice && !noticeList.value.some((item) => item.id === notice.id)) noticeList.value.unshift(notice);
  go(next);
}

function refreshCountdown() {
  remaining.value = Math.max(0, Math.ceil((resendAt - Date.now()) / 1000));
  if (remaining.value === 0 && smsTimer) {
    clearInterval(smsTimer);
    smsTimer = undefined;
  }
}

function startCountdown() {
  if (smsTimer) clearInterval(smsTimer);
  resendAt = Date.now() + 60_000;
  refreshCountdown();
  smsTimer = setInterval(refreshCountdown, 1000);
}

async function requestCode() {
  if (smsBusy.value) return;
  const error = screen.value === 3 ? codeError : loginError;
  error.value = "";
  const number = mobile.value;
  const currentScreen = screen.value;
  const validMobile = smsLoginEnabled ? /^[67]\d{8}$/ : /^\d{9}$/;
  if (!validMobile.test(number) || !accepted.value) {
    error.value = accepted.value ? "请输入有效的西班牙手机号" : "请先阅读并同意协议";
    return;
  }
  if (!smsLoginEnabled && !demoEnabled) {
    error.value = "短信登录暂未开放";
    return;
  }
  refreshCountdown();
  try {
    smsBusy.value = true;
    if (issuedFor !== number || remaining.value === 0) {
      if (smsLoginEnabled) await memberAuth.sendRemitSms("+34" + number);
      if (mobile.value !== number || screen.value !== currentScreen) return;
      issuedFor = number;
      startCountdown();
    }
    code.value = "";
    codeError.value = "";
    go(3);
  } catch (reason) {
    error.value = getErrorMessage(reason, "验证码发送失败，请稍后重试");
  } finally {
    smsBusy.value = false;
  }
}

function syncCodeOnBlur(event: { detail: { value?: string } }) {
  // H5 的输入事件会节流；点提交前失焦时先同步实际输入值。
  const value = event.detail.value;
  if (typeof value === "string") code.value = value;
}

async function verifyCode() {
  if (smsBusy.value) return;
  if (!validCode.value || mobile.value !== issuedFor || (!smsLoginEnabled && (!demoEnabled || code.value !== demoCode))) {
    codeError.value = "验证码错误，请重新输入";
    return;
  }
  try {
    smsBusy.value = true;
    codeError.value = "";
    if (smsLoginEnabled) {
      const number = issuedFor;
      const result = await memberAuth.loginRemitSms("+34" + number, code.value);
      if (screen.value !== 3 || mobile.value !== number) return;
      pendingLogin = result;
    }
    if (screen.value === 3) go(4);
    else pendingLogin = undefined;
  } catch (reason) {
    codeError.value = getErrorMessage(reason, "登录失败，请重试");
  } finally {
    smsBusy.value = false;
  }
}

function showAgreement(kind: "terms" | "privacy" | "rules") {
  const content = {
    terms: ["用户协议说明", "本地预览使用演示账号。正式使用前，请查阅上线时提供的用户协议。"],
    privacy: ["隐私说明", "预览中的手机号、客服内容和选取的补件资料仅用于本次设备上的操作演示。"],
    rules: ["汇款服务说明", notices.join("\n")],
  }[kind];
  uni.showModal({ title: content[0], content: content[1], showCancel: false });
}

function confirmNotices() {
  if (noticeOnboarding.value && smsLoginEnabled && pendingLogin) {
    sessionService.setToken(pendingLogin.token);
    sessionService.setCurrentUser(pendingLogin.member);
    pendingLogin = undefined;
    localSessionOpen.value = true;
  } else if (noticeOnboarding.value && demoEnabled) go(5);
  else back();
}

function scheduleSplash() {
  splashTimer = setTimeout(() => {
    if (screen.value === 1) go(2);
  }, 1400);
}

function closeLocalSession() {
  localSessionOpen.value = false;
  go(2);
}

onMounted(() => {
  if (!localSessionOpen.value) scheduleSplash();
});
onShow(refreshCountdown);
onShow(() => {
  pageVisible.value = true;
  localSession.value?.refreshSupport();
  void refreshSupportMessages();
});
onHide(() => { pageVisible.value = false; });
onBackPress(() => {
  if (localSessionOpen.value) {
    localSession.value?.back();
    return true;
  }
  if (countryOpen.value) {
    closeSheet();
    return true;
  }
  if (screen.value === 1 || screen.value === 6) return false;
  back();
  return true;
});
onUnmounted(() => {
  clearTimeout(splashTimer);
  if (smsTimer) clearInterval(smsTimer);
});
</script>
