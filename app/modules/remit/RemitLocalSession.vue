<template>
  <view class="remit" data-mode="local-session" :data-screen="chatOpen ? 36 : undefined">
    <view v-if="!member || subPageOpen || profileOpen" class="top session-top">
      <button v-if="showBack" class="back-hit" role="button" tabindex="0" aria-label="返回" @tap="back">
        <image class="round-icon" src="/static/remit/back.svg" mode="aspectFit" />
      </button>
      <text>{{ pageTitle }}</text>
      <button v-if="profileOpen && !subPageOpen" class="top-action" @tap="settingsOpen = true">设置</button>
    </view>

    <RemitInbox v-if="inboxOpen" v-show="!ordersOpen && !ticketsOpen" ref="inbox" :target-notice="targetNotice" @expired="expire"
      @unread="inboxCount = $event" @ticket="openTicket" @order="openNoticeOrder" />
    <RemitHelp v-if="helpOpen" v-show="!chatOpen" @contact="chatOpen = true" />

    <view v-if="!member && props.allowLocalLogin" class="body auth">
      <text class="heading">测试账号登录</text>
      <text class="description">仅用于本地联调。</text>
      <view class="phone card"><input v-model="email" placeholder="请输入测试邮箱" aria-label="测试邮箱" /></view>
      <view class="phone card"><input v-model="password" password placeholder="请输入密码" aria-label="密码" /></view>
    </view>

    <RemitCoupons v-else-if="couponsOpen" @select="selectCoupon" @expired="expire" />
    <RemitDrafts v-else-if="draftsOpen" @resume="resumeDraft" @expired="expire" />
    <RemitOrders
      v-else-if="ordersOpen && !ticketsOpen" ref="orderView" :initial-order="returnOrder" :detail-only="inboxOpen"
      @expired="expire" @detail="orderDetail = $event" @support="supportForOrder"
    />
    <RemitTickets v-else-if="ticketsOpen" ref="tickets" :initial-ticket="noticeTicket" :order-reference="ticketOrder" @expired="expire" />
    <RemitKyc v-else-if="kycOpen" :page-visible="props.pageVisible" @expired="expire" />
    <RemitMaterials v-else-if="materialsOpen" @expired="expire" />
    <RemitClosure v-else-if="closureOpen" @expired="expire" />
    <RemitSupportConversation
      v-else-if="chatOpen" :live="live" :messages="messages" :sending="sending" :status="status"
      :send="send" @refresh="refresh()"
    />
    <view v-else-if="settingsOpen" class="body account-content">
      <text class="account-section-title">账户与安全</text>
      <button class="card account-link" @tap="closureOpen = true">
        <text>账号注销与记录</text>
        <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
      </button>
      <!-- #ifdef APP-PLUS || H5 -->
      <button class="card account-link" @tap="emit('privacy')">
        <text>隐私政策</text>
        <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
      </button>
      <!-- #endif -->
      <text v-if="error" class="hint offer" role="alert">{{ error }}</text>
      <button class="secondary account-logout" :disabled="busy" @tap="logout">退出登录</button>
    </view>
    <view v-else-if="profileOpen && member && !inboxOpen && !helpOpen" class="body account-content">
      <view class="card account-identity">
        <text class="account-avatar">{{ member.userName.slice(0, 1) || '会' }}</text>
        <text class="account-name">{{ member.userName }}</text>
      </view>
      <view class="account-links">
        <button v-for="link in accountLinks" :key="link.label" class="card account-link" @tap="link.open()">
          <image class="account-icon" :src="link.icon" mode="aspectFit" />
          <text class="account-link-text">{{ link.label }}</text>
          <text v-if="link.badge" class="pill">{{ link.badge }}</text>
          <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
        </button>
      </view>
    </view>
    <view v-else-if="onHome" class="home-content session-home">
      <view class="line home-head">
        <view class="brand">
          <view class="brand-tile"><image src="/static/remit/logo-home.svg" mode="aspectFit" /></view>
          <view><text class="brand-name">筷子闪汇</text><text class="brand-en">KUAIZI REMIT</text></view>
        </view>
        <button class="support icon-control home-support" role="button" aria-label="帮助与客服" :disabled="busy" @tap="helpOpen = true">
          <image src="/static/remit/support.svg" mode="aspectFit" />
          <text v-if="unread" class="home-support-unread" :aria-label="`${unread} 条未读客服消息`" />
        </button>
      </view>
      <view v-if="announcement" class="promotion payment-notice">{{ announcement }}</view>
      <view class="card quote-card">
        <view class="line quote-label"><text>汇款金额</text><text class="pill">EUR → CNY</text></view>
        <view class="currency">
          <view class="flag eu"><image src="/static/remit/eu.png" mode="scaleToFill" /></view><text>EUR</text>
          <input v-model="amount" type="digit" maxlength="12" class="currency-amount" aria-label="联调汇款金额" />
        </view>
        <template v-if="quote">
          <view class="line quote-label"><text>预计到账</text><text class="muted">{{ quote.eta }}</text></view>
          <view class="currency">
            <image class="flag" src="/static/remit/china.svg" mode="aspectFit" /><text>CNY</text>
            <text class="currency-amount accent" :class="{ 'wide-amount': formatAmount(quote.payout_minor).length >= 11 }">
              {{ formatAmount(quote.payout_minor) }}
            </text>
          </view>
        </template>
        <view class="line quote-label"><text>人民币到账方式</text></view>
        <view class="line">
          <button class="inline-action" :class="{ accent: payoutMethod === 'bank' }" @tap="payoutMethod = 'bank'">银行卡</button>
          <button class="inline-action" :class="{ accent: payoutMethod === 'wechat' }" @tap="payoutMethod = 'wechat'">微信收款</button>
        </view>
        <view class="line quote-label"><text>付款方式</text></view>
        <view class="line">
          <button class="inline-action" :class="{ accent: paymentMethod === 'sepa' }" @tap="paymentMethod = 'sepa'">SEPA</button>
          <button class="inline-action" :class="{ accent: paymentMethod === 'applePay' }" @tap="paymentMethod = 'applePay'">Apple Pay</button>
          <button class="inline-action" :class="{ accent: paymentMethod === 'card' }" @tap="paymentMethod = 'card'">银行卡</button>
        </view>
      </view>

      <view v-if="quote" class="quote-breakdown" aria-label="联调报价结果">
        <view class="line"><text>{{ quote.can_submit ? '锁定汇率' : '试算汇率' }}</text><text>{{ (quote.rate_ten_thousandths / 10000).toFixed(4) }}</text></view>
        <view class="line"><text>汇款手续费</text><text>€{{ formatAmount(quote.base_fee_minor) }}</text></view>
        <view v-if="quote.discount_minor" class="line"><text>优惠减免</text><text>−€{{ formatAmount(quote.discount_minor) }}</text></view>
        <view class="line"><text>通道费</text><text>€{{ formatAmount(quote.channel_fee_minor) }}</text></view>
        <view class="line"><text>合计支付</text><text>€{{ formatAmount(quote.total_payable_minor) }}</text></view>
        <view v-if="quote.payment_notice" class="promotion payment-notice">{{ quote.payment_notice }}</view>
        <view v-if="quote.record_no" class="line quote-reference">
          <text>{{ quote.can_submit ? '报价编号' : '试算编号' }}</text>
          <button class="inline-action accent" @tap="copyQuoteReference(quote.record_no)">复制编号</button>
        </view>
        <text v-if="quote.record_no" class="description quote-reference-value">{{ quote.record_no }}</text>
        <template v-if="quote.can_submit && quote.expires_at">
          <text class="description">汇率有效至 {{ new Date(quote.expires_at * 1000).toLocaleTimeString() }}</text>
          <button class="primary" :disabled="busy" @tap="confirmOrder(quote)">选择到账账户并确认</button>
        </template>
        <button v-else-if="quote.record_no" class="inline-action accent" :disabled="busy" @tap="saveDraft(quote.record_no)">保存为草稿</button>
      </view>
      <text v-if="quote?.mode === 'local_estimate'" class="description">试算报价不锁定汇率。</text>
      <view class="line session-quote-links">
        <button class="inline-action accent" :disabled="busy" @tap="couponsOpen = true">
          {{ selectedCoupon ? `优惠券 · ${selectedCoupon.title}` : '我的优惠券' }}
        </button>
        <button class="inline-action accent" :disabled="busy" @tap="inboxOpen = true">
          消息通知<text v-if="inboxCount" class="pill">{{ inboxCount }} 条未读</text>
        </button>
      </view>
    </view>

    <view v-if="!member && props.allowLocalLogin" class="footer">
      <text v-if="error" class="hint offer" role="alert">{{ error }}</text>
      <button class="primary" :disabled="busy" @tap="login">{{ busy ? '请稍候' : '登录' }}</button>
    </view>
    <view v-if="showTabs" class="session-bottom">
      <view v-if="onHome" class="footer quote-footer">
        <text v-if="error" class="hint offer" role="alert">{{ error }}</text>
        <button class="primary" :disabled="busy" @tap="estimate">{{ busy ? '请稍候' : '获取汇款报价' }}</button>
      </view>
      <view class="tabs" aria-label="主导航">
        <button
          v-for="tab in sessionTabs" :key="tab.key" class="tab" :class="{ active: activeTab === tab.key }"
          :aria-current="activeTab === tab.key ? 'page' : undefined" :disabled="busy || claimingOffer" @tap="selectTab(tab.key)"
        >
          <image :src="tab.icon" mode="aspectFit" /><text>{{ tab.label }}</text>
        </button>
      </view>
    </view>
    <RemitCouponOffer
      v-if="homeOffer && onCouponHome && !busy"
      :coupon="homeOffer"
      :busy="claimingOffer"
      :error="offerError"
      @claim="claimHomeOffer"
      @dismiss="dismissHomeOffer"
    />
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { AuthRepository } from '@/modules/auth/repository/AuthRepository';
import type { CurrentUser } from '@/modules/profile/domain/CurrentUser';
import request, { UnauthorizedError } from '@/request/http';
import { sessionService } from '@/shared/session/application/SessionService';
import { formatAmount } from './previewOrder';
import RemitInbox from './RemitInbox.vue';
import RemitSupportConversation from './RemitSupportConversation.vue';
import RemitHelp from './RemitHelp.vue';
import { useSupportChat } from './useSupportChat';
import RemitCoupons from './RemitCoupons.vue';
import RemitCouponOffer from './RemitCouponOffer.vue';
import RemitDrafts, { type RemitDraft } from './RemitDrafts.vue';
import RemitOrders from './RemitOrders.vue';
import RemitMaterials from './RemitMaterials.vue';
import RemitTickets from './RemitTickets.vue';
import RemitKyc from './RemitKyc.vue';
import RemitClosure from './RemitClosure.vue';
import type { MemberCoupon } from './memberCoupon';
import { pendingNotice, syncMemberPush } from './memberPush';

interface QuoteEstimate {
  record_no: string;
  mode: 'local_estimate' | 'locked';
  can_submit: boolean;
  expires_at?: number;
  payout_minor: number;
  rate_ten_thousandths: number;
  base_fee_minor: number;
  discount_minor: number;
  channel_fee_minor: number;
  total_payable_minor: number;
  eta: string;
  payment_notice: string;
}

const auth = new AuthRepository();
const props = defineProps<{ pageVisible: boolean; allowLocalLogin: boolean }>();
const member = ref<CurrentUser>();
const email = ref('');
const password = ref('');
const amount = ref('100');
const paymentMethod = ref<'sepa' | 'applePay' | 'card'>('sepa');
const payoutMethod = ref<'bank' | 'wechat'>('bank');
const quote = ref<QuoteEstimate>();
const busy = ref(false);
const inboxOpen = ref(false);
const targetNotice = ref<string>();
const chatOpen = ref(false);
const helpOpen = ref(false);
const couponsOpen = ref(false);
const draftsOpen = ref(false);
const ordersOpen = ref(false);
const profileOpen = ref(false);
const settingsOpen = ref(false);
const materialsOpen = ref(false);
const closureOpen = ref(false);
const orderDetail = ref(false);
const returnOrder = ref<string>();
const ticketOrder = ref<string>();
const orderView = ref<InstanceType<typeof RemitOrders>>();
const ticketsOpen = ref(false);
const kycOpen = ref(false);
const tickets = ref<InstanceType<typeof RemitTickets>>();
const noticeTicket = ref<string>();
const selectedCoupon = ref<MemberCoupon | null>(null);
const homeOffer = ref<MemberCoupon>();
const claimingOffer = ref(false);
const offerError = ref('');
const dismissedOffers = new Set<string>();
let offerRequest = 0;
const error = ref('');
let quoteRevision = 0;
const inbox = ref<InstanceType<typeof RemitInbox>>();
const inboxCount = ref(0);
const announcement = ref<string | null>(null);
let inboxRequest = 0;
let announcementRequest = 0;

const subPageOpen = computed(() =>
  inboxOpen.value || chatOpen.value || helpOpen.value || couponsOpen.value || draftsOpen.value || ordersOpen.value || ticketsOpen.value ||
  kycOpen.value || materialsOpen.value || closureOpen.value || settingsOpen.value,
);
const onHome = computed(() => Boolean(member.value) && !subPageOpen.value && !profileOpen.value);
const onCouponHome = computed(() => onHome.value && props.pageVisible);
const onOrderTab = computed(() => ordersOpen.value && !ticketsOpen.value && !inboxOpen.value && !orderDetail.value);
const showTabs = computed(() => Boolean(member.value) && (!subPageOpen.value || onOrderTab.value));
const showBack = computed(() => (subPageOpen.value && !onOrderTab.value) || (!member.value && !busy.value));

const sessionTabs = [
  { key: 'home', label: '首页', icon: '/static/remit/tab-home.svg' },
  { key: 'orders', label: '订单', icon: '/static/remit/tab-orders.svg' },
  { key: 'profile', label: '我的', icon: '/static/remit/tab-profile.svg' },
] as const;
type SessionTab = (typeof sessionTabs)[number]['key'];
const activeTab = computed<SessionTab>(() => ordersOpen.value ? 'orders' : profileOpen.value ? 'profile' : 'home');

const pageTitle = computed(() => {
  if (chatOpen.value) return '筷子闪汇客服';
  if (helpOpen.value) return '帮助与客服';
  if (couponsOpen.value) return '我的优惠券';
  if (draftsOpen.value) return '汇款草稿';
  if (ticketsOpen.value) return '客服工单';
  if (ordersOpen.value) return orderDetail.value ? '订单详情' : '汇款订单';
  if (inboxOpen.value) return '消息通知';
  if (kycOpen.value) return '身份认证';
  if (materialsOpen.value) return '补充资料';
  if (closureOpen.value) return '账号注销';
  if (settingsOpen.value) return '设置';
  if (profileOpen.value) return '我的';
  return member.value ? '汇款报价' : props.allowLocalLogin ? '测试账号登录' : '获取会话';
});
const { live, messages, refresh, refreshView, send, sending, status, unread } = useSupportChat(
  () => chatOpen.value && props.pageVisible,
  {
    listen: () => Boolean(member.value),
    expired: expire,
    notificationsChanged: refreshNotifications,
    couponsChanged: () => { void refreshHomeOffer(); },
  },
);

const accountLinks = computed(() => [
  { label: '我的优惠券', icon: '/static/remit/gift.png', open: () => { couponsOpen.value = true; } },
  { label: '汇款草稿', icon: '/static/remit/document.svg', open: () => { draftsOpen.value = true; } },
  { label: '身份认证', icon: '/static/remit/identity.svg', open: () => { kycOpen.value = true; } },
  { label: '补充资料', icon: '/static/remit/document.svg', open: () => { materialsOpen.value = true; } },
  { label: '消息通知', icon: '/static/remit/mail.svg', open: () => { inboxOpen.value = true; }, badge: inboxCount.value || undefined },
  { label: '帮助与客服', icon: '/static/remit/support.svg', open: () => { helpOpen.value = true; }, badge: unread.value || undefined },
  { label: '客服工单', icon: '/static/remit/document.svg', open: () => { ticketsOpen.value = true; } },
  { label: '账户安全', icon: '/static/remit/shield.svg', open: () => { settingsOpen.value = true; } },
]);

const emit = defineEmits<{ back: []; privacy: [] }>();

function selectTab(tab: SessionTab) {
  if (tab === activeTab.value) return;
  profileOpen.value = tab === 'profile';
  ordersOpen.value = tab === 'orders';
  orderDetail.value = false;
  returnOrder.value = undefined;
}

function back() {
  if (homeOffer.value && onCouponHome.value) {
    dismissHomeOffer();
  } else if (chatOpen.value) {
    chatOpen.value = false;
  } else if (helpOpen.value) {
    helpOpen.value = false;
  } else if (couponsOpen.value) {
    couponsOpen.value = false;
  } else if (draftsOpen.value) {
    draftsOpen.value = false;
  } else if (ticketsOpen.value) {
    if (ticketOrder.value) {
      ticketOrder.value = undefined;
      ticketsOpen.value = false;
    } else if (noticeTicket.value) {
      ticketsOpen.value = false;
      noticeTicket.value = undefined;
      inboxOpen.value = true;
    } else if (!tickets.value?.back()) {
      ticketsOpen.value = false;
    }
  } else if (ordersOpen.value) {
    if (inboxOpen.value) {
      ordersOpen.value = false;
      orderDetail.value = false;
      returnOrder.value = undefined;
    } else {
      returnOrder.value = undefined;
      if (!orderView.value?.back()) ordersOpen.value = false;
    }
  } else if (inboxOpen.value) {
    inboxOpen.value = false;
    targetNotice.value = undefined;
  } else if (kycOpen.value) {
    kycOpen.value = false;
  } else if (materialsOpen.value) {
    materialsOpen.value = false;
  } else if (closureOpen.value) {
    closureOpen.value = false;
  } else if (settingsOpen.value) {
    settingsOpen.value = false;
    error.value = '';
  } else if (profileOpen.value) {
    profileOpen.value = false;
  } else if (!member.value && !busy.value) {
    emit('back');
  }
}

function supportForOrder(reference: string) {
  returnOrder.value = reference;
  ticketOrder.value = reference;
  ticketsOpen.value = true;
}

defineExpose({
  back,
  refreshSupport: () => {
    if (member.value) {
      void refreshView();
      refreshNotifications();
      syncMemberPush();
    }
  },
});

function refreshNotifications() {
  const token = sessionService.getToken();
  if (!token || !props.pageVisible) return;
  if (inbox.value) {
    inbox.value.refresh();
    return;
  }
  const id = ++inboxRequest;
  void request.get<{ unread_count: number }>('app/notifications/unread', {}, { handleUnauthorized: false })
    .then(result => {
      if (id === inboxRequest && !inboxOpen.value && sessionService.getToken() === token) inboxCount.value = result.unread_count;
    })
    .catch(error => {
      if (sessionService.getToken() === token && error instanceof UnauthorizedError) expire();
    });
}

watch(member, (profile) => {
  if (profile) {
    refreshNotifications();
    syncMemberPush();
  }
});

watch([pendingNotice, member, () => props.pageVisible, busy, subPageOpen, ordersOpen], () => {
  if (!pendingNotice.value || !member.value || !props.pageVisible || busy.value) return;
  if (ordersOpen.value) return;
  if (subPageOpen.value && !inboxOpen.value) return;
  targetNotice.value = pendingNotice.value;
  pendingNotice.value = undefined;
  inboxOpen.value = true;
}, { immediate: true });

async function refreshHomeOffer() {
  const token = sessionService.getToken();
  if (!token || !onCouponHome.value || claimingOffer.value || homeOffer.value) return;
  const id = ++offerRequest;
  try {
    const { offer } = await request.get<{ offer: MemberCoupon | null }>(
      'app/coupons/home-offer', {}, { handleUnauthorized: false },
    );
    if (id === offerRequest && onCouponHome.value && sessionService.getToken() === token
      && offer && !dismissedOffers.has(offer.id)) {
      homeOffer.value = offer;
    }
  } catch (reason) {
    if (id === offerRequest && sessionService.getToken() === token && reason instanceof UnauthorizedError) expire();
  }
}

watch(onCouponHome, (active) => {
  if (active) {
    void refreshHomeOffer();
    refreshAnnouncement();
  } else {
    offerRequest++;
    announcementRequest++;
  }
});

function refreshAnnouncement() {
  const token = sessionService.getToken();
  const revision = ++announcementRequest;
  void request.get<{ announcement: string | null }>('app/remittance/announcement', {}, { handleUnauthorized: false })
    .then(result => {
      if (revision === announcementRequest && sessionService.getToken() === token) {
        announcement.value = result.announcement;
      }
    })
    .catch(error => {
      if (revision === announcementRequest && sessionService.getToken() === token && error instanceof UnauthorizedError) {
        expire();
      }
    });
}

function dismissHomeOffer() {
  if (!homeOffer.value || claimingOffer.value) return;
  dismissedOffers.add(homeOffer.value.id);
  homeOffer.value = undefined;
  offerError.value = '';
}

async function claimHomeOffer() {
  const coupon = homeOffer.value;
  const token = sessionService.getToken();
  if (!coupon || !token || claimingOffer.value) return;
  claimingOffer.value = true;
  offerError.value = '';
  try {
    await request.post(`app/coupons/${encodeURIComponent(coupon.id)}/claim`, {}, { handleUnauthorized: false });
    if (sessionService.getToken() !== token) return;
    homeOffer.value = undefined;
    dismissedOffers.add(coupon.id);
    uni.showToast({ title: '已领取，可在我的优惠券查看', icon: 'none' });
  } catch (reason) {
    if (sessionService.getToken() !== token) return;
    if (reason instanceof UnauthorizedError) expire();
    else offerError.value = reason instanceof Error ? reason.message : '领取失败，请重试';
  } finally {
    if (sessionService.getToken() === token) claimingOffer.value = false;
  }
}

function selectCoupon(coupon: MemberCoupon | null) {
  selectedCoupon.value = coupon;
  couponsOpen.value = false;
  profileOpen.value = false;
}

function openTicket(id: string) {
  noticeTicket.value = id;
  ticketsOpen.value = true;
}

function openNoticeOrder(reference: string) {
  returnOrder.value = reference;
  orderDetail.value = true;
  ordersOpen.value = true;
}

watch([amount, paymentMethod, payoutMethod, selectedCoupon], () => {
  quoteRevision++;
  quote.value = undefined;
  error.value = '';
}, { flush: 'sync' });

async function perform(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (reason instanceof UnauthorizedError) expire();
    error.value = reason instanceof Error ? reason.message : '操作失败，请稍后再试。';
  } finally {
    busy.value = false;
  }
}

function clearSession() {
  sessionService.clearAuthSession();
  member.value = undefined;
  quote.value = undefined;
  inboxOpen.value = false;
  inboxCount.value = 0;
  targetNotice.value = undefined;
  pendingNotice.value = undefined;
  inboxRequest++;
  announcementRequest++;
  announcement.value = null;
  chatOpen.value = false;
  helpOpen.value = false;
  couponsOpen.value = false;
  draftsOpen.value = false;
  ordersOpen.value = false;
  profileOpen.value = false;
  settingsOpen.value = false;
  materialsOpen.value = false;
  closureOpen.value = false;
  orderDetail.value = false;
  returnOrder.value = undefined;
  ticketOrder.value = undefined;
  ticketsOpen.value = false;
  kycOpen.value = false;
  noticeTicket.value = undefined;
  homeOffer.value = undefined;
  claimingOffer.value = false;
  offerError.value = '';
  dismissedOffers.clear();
  offerRequest++;
  selectedCoupon.value = null;
}

function expire() {
  clearSession();
  if (!props.allowLocalLogin) emit('back');
  error.value = '登录状态已过期，请重新登录';
}

function login() {
  return perform(async () => {
    const result = await auth.loginLocal(email.value.trim(), password.value);
    sessionService.setToken(result.token);
    sessionService.setCurrentUser(result.member);
    member.value = result.member;
    password.value = '';
  });
}

function logout() {
  return perform(async () => {
    await auth.logoutLocal();
    clearSession();
    if (!props.allowLocalLogin) emit('back');
  });
}

function estimate() {
  return perform(async () => {
    quote.value = undefined;
    const match = /^(\d{1,9})(?:\.(\d{0,2}))?$/.exec(amount.value);
    const principal = match ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) : 0;
    if (principal < 10_000 || principal > 99_999_999_900) {
      throw new Error('请输入有效金额，最低 €100，最多两位小数。');
    }
    const revision = quoteRevision;
    const result = await request.post<QuoteEstimate>('app/remittance/quotes', {
      principal_minor: principal,
      payment_method: paymentMethod.value,
      payout_method: payoutMethod.value,
      coupon_id: selectedCoupon.value ? Number(selectedCoupon.value.id) : null,
    }, { handleUnauthorized: false });
    if (revision !== quoteRevision) return;
    if ((result.mode === 'local_estimate' && result.can_submit) || (result.mode === 'locked' && !result.expires_at)) {
      throw new Error('暂时无法显示报价，请稍后重试。');
    }
    quote.value = result;
  });
}

function choosePayout(labels: string[]): Promise<number | undefined> {
  return new Promise(resolve => uni.showActionSheet({
    itemList: labels,
    success: ({ tapIndex }) => resolve(tapIndex),
    fail: () => resolve(undefined),
  }));
}

function confirmOrder(locked: QuoteEstimate) {
  return perform(async () => {
    if (!locked.expires_at || locked.expires_at * 1000 <= Date.now()) {
      throw new Error('锁汇已过期，请重新报价');
    }
    const options = { handleUnauthorized: false };
    const accounts = await request.get<{ reference: string; label: string }[]>(
      'app/remittance/payout-accounts', { payout_method: payoutMethod.value }, options,
    );
    if (!accounts.length) throw new Error('当前没有已核验的本人到账账户');
    const index = await choosePayout(accounts.map(account => account.label));
    const account = index === undefined ? undefined : accounts[index];
    if (!account || quote.value?.record_no !== locked.record_no) return;
    const confirmed = await new Promise<boolean>(resolve => uni.showModal({
      title: '确认汇款订单',
      content: account.label + '，合计支付 €' + formatAmount(locked.total_payable_minor),
      confirmText: '确认下单',
      success: ({ confirm }) => resolve(confirm),
      fail: () => resolve(false),
    }));
    if (!confirmed || quote.value?.record_no !== locked.record_no) return;
    const order = await request.post<{ reference: string }>('app/remittance/orders', {
      quote_reference: locked.record_no, payout_account: account.reference,
    }, options);
    returnOrder.value = order.reference;
    orderDetail.value = true;
    ordersOpen.value = true;
    selectedCoupon.value = null;
    quote.value = undefined;
  });
}

function copyQuoteReference(reference: string) {
  uni.setClipboardData({ data: reference });
}

function saveDraft(reference: string) {
  return perform(async () => {
    await request.put(`app/remittance/drafts/${reference}`, {}, { handleUnauthorized: false });
    uni.showToast({ title: '已保存汇款草稿', icon: 'none' });
  });
}

function resumeDraft(draft: RemitDraft) {
  amount.value = (draft.principal_minor / 100).toFixed(2);
  paymentMethod.value = draft.payment_method;
  payoutMethod.value = draft.payout_method;
  selectedCoupon.value = null;
  draftsOpen.value = false;
  profileOpen.value = false;
  void estimate();
}

onMounted(() => {
  if (!sessionService.getToken()) return;
  void perform(async () => {
    const profile = await auth.current();
    sessionService.setCurrentUser(profile);
    member.value = profile;
  });
});
</script>

<style scoped>
.payment-notice {
  margin: 12rpx 0;
  overflow-wrap: anywhere;
}
.quote-reference-value {
  display: block;
  overflow-wrap: anywhere;
}
.session-top {
  flex-shrink: 0;
  background: #fff;
  border-bottom: 1rpx solid #e7e7ef;
}
.session-home {
  padding-top: 28rpx;
}
.home-support {
  position: relative;
}
.home-support-unread {
  position: absolute;
  top: 2rpx;
  right: 2rpx;
  width: 14rpx;
  height: 14rpx;
  border: 3rpx solid #fff;
  border-radius: 50%;
  background: var(--remit-accent);
}
.session-quote-links {
  padding: 14rpx 0 18rpx;
}
.account-content {
  flex: 1;
}
.account-identity,
.account-link {
  display: flex;
  align-items: center;
  gap: 22rpx;
  padding: 24rpx;
  border-color: #e7e7ef;
}
.account-avatar,
.account-icon {
  flex-shrink: 0;
  border-radius: 24rpx;
  background: #eeebff;
}
.account-avatar {
  display: grid;
  place-items: center;
  width: 100rpx;
  height: 100rpx;
  border-radius: 50%;
  font-size: 40rpx;
}
.account-name {
  min-width: 0;
  font-size: 34rpx;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.account-links {
  display: grid;
  gap: 16rpx;
  margin-top: 24rpx;
}
.account-link {
  width: 100%;
  min-height: 100rpx;
  color: #242733;
  font-size: 28rpx;
  font-weight: 600;
  line-height: 1.4;
  text-align: left;
}
.account-icon {
  width: 36rpx;
  height: 36rpx;
  padding: 12rpx;
}
.account-link-text {
  min-width: 0;
}
.account-link .chevron {
  flex-shrink: 0;
  margin-left: auto;
}
.account-section-title {
  display: block;
  margin-bottom: 16rpx;
  color: #6d7180;
  font-size: 24rpx;
}
.account-logout {
  width: 100%;
  margin-top: 24rpx;
  color: #b92e47;
}
.session-bottom {
  position: sticky;
  bottom: 0;
  z-index: 1;
  flex-shrink: 0;
  margin-top: auto;
  background: #fff;
  border-top: 1rpx solid #e7e7ef;
}
.session-bottom .quote-footer {
  padding-bottom: 16rpx;
}
.session-bottom .tabs {
  border-top: 1rpx solid #e7e7ef;
}
</style>
