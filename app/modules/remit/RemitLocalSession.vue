<template>
  <view
    class="remit remit-session"
    :class="{ 'remit-home': onHome, 'remit-figma-surface': usesFigmaSurface, 'remit-reference-surface': usesReferenceSurface }"
    data-mode="local-session"
    :data-screen="chatOpen ? 36 : undefined"
  >
    <view v-if="!member || subPageOpen || profileOpen" class="top session-top"
      :class="{ 'figma-session-top': usesFigmaSurface && !orderView?.hasWhiteHeader }">
      <button
        v-if="showBack && !orderView?.showingSentResult" class="back-hit" role="button" tabindex="0" aria-label="返回"
        :disabled="Boolean(confirmation) && busy" @tap="back"
      >
        <view v-if="usesReferenceSurface || chatOpen" class="reference-back">
          <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
        </view>
        <image v-else class="round-icon" src="/static/remit/back.svg" mode="aspectFit" />
      </button>
      <view v-if="chatOpen" class="chat-navigation-title">
        <text>{{ pageTitle }}</text>
        <text class="chat-navigation-status" :class="{ 'is-live': live && !status }">
          {{ live && !status ? '实时更新' : '可留言' }}
        </text>
      </view>
      <text v-else>{{ pageTitle }}</text>
      <button v-if="chatOpen && (!live || status)" class="top-action" @tap="refresh()">刷新</button>
      <button
        v-if="profileOpen && !subPageOpen"
        class="icon-control profile-settings"
        aria-label="设置"
        @tap="settingsOpen = true"
      >
        <image src="/static/remit/settings.svg" mode="aspectFit" aria-hidden="true" />
      </button>
      <button v-if="orderView?.showingSentResult" class="top-action" @tap="back">完成</button>
      <button v-if="inboxOpen && !ordersOpen && !ticketsOpen && !materialsOpen" class="top-action"
        :disabled="!inbox?.canMarkAll" @tap="inbox?.markAll()">全部已读</button>
    </view>

    <RemitInbox
      v-if="inboxOpen" v-show="inboxVisible" ref="inbox" class="session-content"
      :page-visible="props.pageVisible && inboxVisible" :target-notice="targetNotice" @expired="expire"
      @unread="inboxCount = $event" @ticket="openTicket" @order="openNoticeOrder" @material="openNoticeMaterial" />
    <RemitHelp v-if="helpOpen" v-show="!chatOpen" class="session-content" @contact="chatOpen = true" />

    <view v-if="!member && props.allowLocalLogin" class="body auth session-content">
      <text class="heading">测试账号登录</text>
      <text class="description">仅用于本地联调。</text>
      <view class="phone card"><input v-model="email" placeholder="请输入测试邮箱" aria-label="测试邮箱" /></view>
      <view class="phone card"><input v-model="password" password placeholder="请输入密码" aria-label="密码" /></view>
    </view>

    <view v-else-if="paymentEditor" class="payout-selection payment-editor session-content">
      <view class="payout-selection-content">
        <view class="payout-selection-options" role="group" aria-label="付款方式">
          <button v-for="method in paymentOptions" :key="method.value" class="card payout-selection-option"
            :class="{ 'is-selected': paymentEditor === method.value }" :aria-pressed="paymentEditor === method.value"
            @tap="paymentEditor = method.value"
          >
            <view class="payout-selection-icon" aria-hidden="true">
              <image v-if="method.value === 'card'" src="/static/remit/bank-card.svg" mode="aspectFit" />
              <text v-else>{{ method.icon }}</text>
            </view>
            <view class="payout-selection-label">
              <text class="block">{{ method.label }}</text>
              <text class="selection-detail">
                {{ quote && quote.payment_method === method.value
                  ? '付款通道费 €' + formatAmount(quote.channel_fee_minor) + ' · 合计 €' + formatAmount(quote.total_payable_minor)
                  : '获取报价后显示通道费' }}
              </text>
            </view>
            <view class="payout-selection-mark" aria-hidden="true" />
          </button>
        </view>
        <text class="selection-caution payment-caution">{{ quote?.payment_notice || '付款通道费和合计支付金额以所选方式的报价为准。' }}</text>
      </view>
      <view class="footer"><button class="primary" @tap="savePaymentMethod">使用此付款方式</button></view>
    </view>
    <view v-else-if="declarationEditor" class="payout-selection declaration-editor session-content">
      <view class="payout-selection-content">
        <text class="selection-caution declaration-caution">请按本笔汇款实际情况填写。</text>
        <text class="heading declaration-title">请补充本笔用途</text>
        <view class="declaration-purposes" role="group" aria-label="汇款用途">
          <button v-for="purpose in transferPurposes" :key="purpose.value" class="purpose-option"
            :class="{ 'is-selected': declarationEditor.form.purpose === purpose.value }"
            :aria-pressed="declarationEditor.form.purpose === purpose.value" @tap="setDeclarationChoice('purpose', purpose.value)"
          >{{ purpose.label }}</button>
        </view>
        <textarea v-if="declarationEditor.form.purpose === '05'" v-model="declarationEditor.form.explanation"
          class="card declaration-explanation" maxlength="200"
          placeholder="请说明具体用途（最多200字）" aria-label="具体汇款用途" @input="error = ''"
        />
        <text class="declaration-source-title">资金来源</text>
        <view class="payout-selection-options declaration-sources" role="group" aria-label="资金来源">
          <button v-for="source in fundSources" :key="source.value" class="card payout-selection-option"
            :class="{ 'is-selected': declarationEditor.form.source_of_funds === source.value }"
            :aria-pressed="declarationEditor.form.source_of_funds === source.value"
            @tap="setDeclarationChoice('source_of_funds', source.value)"
          >
            <view class="payout-selection-icon" aria-hidden="true">{{ source.label[0] }}</view>
            <view class="payout-selection-label">
              <text class="block">{{ source.label }}</text>
              <text class="selection-detail">{{ source.description }}</text>
            </view>
            <view class="payout-selection-mark" aria-hidden="true" />
          </button>
        </view>
      </view>
      <view class="footer">
        <text v-if="error" class="hint offer" role="alert">{{ error }}</text>
        <button class="primary" @tap="saveDeclaration">{{ declarationEditor.quote ? '保存并继续' : '保存汇款信息' }}</button>
      </view>
    </view>
    <view v-else-if="payoutSelection && !payoutDraftsOpen" class="payout-selection session-content">
      <view class="payout-selection-content">
        <text class="payout-selection-note">仅支持实名认证用户本人的到账账户，付款前将核验账户信息。</text>
        <text class="payout-section-title">{{ payoutMethod === 'wechat' ? '我的微信收款账户' : '我的银行卡' }}</text>
        <view v-if="payoutSelection.accounts.length" class="payout-selection-options" role="group" aria-label="本人到账账户">
          <button
            v-for="account in payoutSelection.accounts"
            :key="account.reference"
            class="card payout-selection-option"
            :class="{ 'is-selected': payoutSelection.selected === account.reference }"
            :aria-pressed="payoutSelection.selected === account.reference"
            @tap="payoutSelection.selected = account.reference"
          >
            <view class="payout-selection-icon" aria-hidden="true">
              <text v-if="payoutMethod === 'wechat'">微</text>
              <image v-else src="/static/remit/bank-card.svg" mode="aspectFit" />
            </view>
            <view class="payout-selection-label">
              <text class="block">{{ account.label }}</text>
              <text class="selection-detail" :class="{ 'payout-verified': payoutSelection.selected === account.reference }">本人已核验账户</text>
            </view>
            <view class="payout-selection-mark" aria-hidden="true" />
          </button>
        </view>
        <text v-else class="description">当前没有已核验的本人到账账户</text>
        <button class="card payout-selection-option payout-management" :disabled="busy" @tap="payoutDraftsOpen = true">
          <text class="payout-selection-icon" aria-hidden="true">+</text>
          <view class="payout-selection-label">
            <text class="block">{{ payoutMethod === 'bank' ? '添加本人银行卡' : '管理本人收款资料' }}</text>
            <text class="selection-detail">新增资料须先通过核验</text>
          </view>
          <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
        </button>
        <text class="payout-section-title">其他到账方式</text>
        <view class="card payout-selection-option payout-unavailable">
          <text class="payout-selection-icon" aria-hidden="true">支</text>
          <view class="payout-selection-label"><text class="block">支付宝</text><text class="selection-detail">待开放服务</text></view>
          <text class="pill">即将开放</text>
        </view>
      </view>
      <view class="footer">
        <text v-if="error" class="hint offer" role="alert">{{ error }}</text>
        <button class="primary" :disabled="!payoutSelection.selected || busy" @tap="confirmSelectedPayout">使用此到账方式</button>
      </view>
    </view>
    <RemitOrderConfirmation
      v-else-if="confirmation && !payoutDraftsOpen"
      class="session-content"
      :quote="confirmation.quote"
      :account-label="confirmation.account.label"
      :declaration="confirmation.declaration"
      :busy="busy"
      :error="error"
      :page-visible="props.pageVisible"
      @confirm="submitOrder"
      @account="confirmOrder(confirmation.quote)"
      @payment="paymentEditor = confirmation.quote.payment_method"
      @edit="back"
    />
    <RemitCoupons v-else-if="couponsOpen" class="session-content" @select="selectCoupon" @expired="expire" />
    <RemitDrafts v-else-if="draftsOpen" class="session-content" @resume="resumeDraft" @expired="expire" />
    <RemitOrders
      v-else-if="ordersOpen && !ticketsOpen" ref="orderView" :initial-order="returnOrder" :detail-only="inboxOpen"
      :page-visible="props.pageVisible"
      @expired="expire" @detail="orderDetail = $event" @support="supportForOrder"
      @leave="back" @remit="startAnotherRemit"
    />
    <RemitTickets v-else-if="ticketsOpen" ref="tickets" class="session-content"
      :initial-ticket="noticeTicket" :order-reference="ticketOrder" @expired="expire" />
    <RemitPayoutDrafts v-else-if="payoutDraftsOpen" ref="payoutDraftsView" class="session-content" @expired="expire" />
    <RemitMaterials
      v-else-if="materialsOpen" ref="materialsView" :target-reference="materialTarget"
      class="session-content"
      @expired="expire" @leave="closeMaterials"
    />
    <RemitClosure v-else-if="closureOpen" class="session-content" @expired="expire" />
    <template v-else-if="kycOpen || chatOpen">
      <RemitKyc v-if="kycOpen" v-show="!chatOpen" class="session-content" :page-visible="props.pageVisible && !chatOpen"
        @expired="expire" @home="leaveKycForHome" @support="chatOpen = true" />
      <RemitSupportConversation v-if="chatOpen" :messages="messages" :sending="sending" :status="status" :send="send" />
    </template>
    <view v-else-if="settingsOpen" class="body account-content settings-content session-content">
      <text class="account-section-title">账户与安全</text>
      <button class="card account-link" @tap="closureOpen = true">
        <image class="account-icon" src="/static/remit/shield.svg" mode="aspectFit" />
        <view class="account-link-text">
          <text>账号注销与记录</text>
          <text class="account-link-description">申请注销或查看处理进度</text>
        </view>
        <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
      </button>
      <!-- #ifdef APP-PLUS || H5 -->
      <text class="account-section-title legal-section-title">服务与法律</text>
      <button class="card account-link" @tap="emit('terms')">
        <view class="account-link-text">
          <text>用户协议</text>
          <text class="account-link-description">账号使用和汇款服务条款</text>
        </view>
        <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
      </button>
      <button class="card account-link" @tap="emit('privacy')">
        <view class="account-link-text">
          <text>隐私政策</text>
          <text class="account-link-description">个人信息的收集、使用和保护</text>
        </view>
        <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
      </button>
      <!-- #endif -->
      <view class="settings-actions">
        <text v-if="error" class="hint offer" role="alert">{{ error }}</text>
        <button class="secondary account-logout" :disabled="busy" @tap="logout">退出登录</button>
        <text v-if="appVersion" class="settings-version">App 版本 {{ appVersion }}</text>
      </view>
    </view>
    <view v-else-if="profileOpen && member && !inboxOpen && !helpOpen" class="body account-content session-content">
      <view class="card account-identity">
        <text class="account-avatar">{{ member.userName.slice(0, 1) || '会' }}</text>
        <text class="account-name">{{ member.userName }}</text>
      </view>
      <view class="account-remittance-summary" role="group" aria-label="汇款额度">
        <view class="account-summary-column">
          <text class="account-summary-label">本月已汇</text>
          <text v-if="accountSummary" class="account-summary-value">€{{ formatAmount(accountSummary.monthly_sent_principal_minor) }}</text>
          <text v-else class="account-summary-status">{{ accountSummaryLoading ? '查询中' : '暂不可用' }}</text>
          <text class="account-summary-note">按本月下单且已下发统计</text>
        </view>
        <view class="account-summary-column">
          <text class="account-summary-label">剩余额度</text>
          <text v-if="accountSummary?.remaining_limit_minor != null" class="account-summary-value">€{{ formatAmount(accountSummary.remaining_limit_minor) }}</text>
          <text v-else class="account-summary-status">{{ accountSummary ? '待确认' : accountSummaryLoading ? '查询中' : '暂不可用' }}</text>
        </view>
      </view>
      <view class="account-links">
        <button v-for="link in accountLinks" :key="link.label" class="card account-link" @tap="link.open()">
          <image class="account-icon" :src="link.icon" mode="aspectFit" />
          <view class="account-link-text">
            <text>{{ link.label }}</text>
            <text class="account-link-description">{{ link.description }}</text>
          </view>
          <text v-if="link.badge" class="pill">{{ link.badge }}</text>
          <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
        </button>
      </view>
    </view>
    <view v-else-if="onHome" class="session-home-layout">
      <view class="line home-head session-home-head">
        <view class="brand">
          <view class="brand-tile"><image src="/static/remit/logo-home.svg" mode="aspectFit" /></view>
          <view><text class="brand-name">筷子闪汇</text><text class="brand-en">KUAIZI REMIT</text></view>
        </view>
        <button class="support icon-control home-support" role="button" aria-label="帮助与客服"
          hover-class="none" :disabled="busy" @tap="helpOpen = true">
          <image src="/static/remit/support.svg" mode="aspectFit" />
          <text v-if="unread" class="home-support-unread" :aria-label="`${unread} 条未读客服消息`" />
        </button>
      </view>
      <view class="home-content session-home session-content">
        <view v-if="announcement" class="promotion payment-notice">{{ announcement }}</view>
        <text v-if="quote?.simulated" class="description">本地模拟报价，不会扣款。</text>
        <view class="card quote-card">
          <view class="line quote-label"><text>汇款金额</text><text class="pill">EUR → CNY</text></view>
          <view class="currency">
            <view class="flag eu"><image src="/static/remit/eu.png" mode="scaleToFill" /></view><text>EUR</text>
            <input v-model="amount" type="digit" maxlength="12" class="currency-amount" aria-label="联调汇款金额" />
          </view>
          <template v-if="quote">
            <view class="line quote-label"><text>收款金额</text><text class="muted">{{ quote.eta }}</text></view>
            <view class="currency">
              <image class="flag" src="/static/remit/china.svg" mode="aspectFit" /><text>CNY</text>
              <text class="currency-amount accent" :class="{ 'wide-amount': formatAmount(quote.payout_minor).length >= 11 }">
                {{ formatAmount(quote.payout_minor) }}
              </text>
            </view>
          </template>
          <view class="line payout">
            <view>
              <text>{{ payoutMethod === 'bank' ? '银行卡收款' : '微信收款' }}</text>
              <text class="muted block">人民币到账方式</text>
            </view>
            <button class="inline-action accent" hover-class="none" :disabled="busy" @tap="changePayoutMethod">更换</button>
          </view>
        </view>

        <view v-if="quote" class="quote-breakdown session-quote-costs" aria-label="报价费用">
          <view class="line"><text>{{ quote.can_submit ? '筷子闪汇锁定汇率' : '筷子闪汇试算汇率' }}</text><text>{{ (quote.rate_ten_thousandths / 10000).toFixed(4) }}</text></view>
          <view class="line quote-fee-line">
            <text>汇款手续费</text>
            <view class="inline quote-fee-values">
              <text v-if="quote.discount_minor" class="strike">€{{ formatAmount(quote.base_fee_minor) }}</text>
              <text>€{{ formatAmount(quote.base_fee_minor - quote.discount_minor) }}</text>
            </view>
          </view>
          <view v-if="quote.channel_fee_minor" class="line"><text>付款通道费</text><text>€{{ formatAmount(quote.channel_fee_minor) }}</text></view>
          <button class="line quote-coupon-row" hover-class="none" :disabled="busy" @tap="couponsOpen = true">
            <text>优惠券</text>
            <view class="inline">
              <text :class="{ offer: selectedCoupon }">{{ selectedCoupon?.title ?? '选择优惠券' }}</text>
              <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
            </view>
          </button>
          <view v-if="quote.payment_notice" class="promotion payment-notice">{{ quote.payment_notice }}</view>
        </view>

        <view class="card home-quote-options">
          <button class="line home-quote-option" hover-class="none" :disabled="busy" @tap="paymentEditor = paymentMethod">
            <text class="quote-label">付款方式</text>
            <text>{{ paymentOptions.find(method => method.value === paymentMethod)?.label }}</text>
            <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
          </button>
          <button class="line home-quote-option" hover-class="none" :disabled="busy" @tap="openDeclaration()">
            <text class="quote-label">汇款用途与资金来源</text>
            <text>{{ declarationError(declaration) ? '待填写' : '已填写' }}</text>
            <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
          </button>
        </view>

        <view v-if="quote" class="quote-breakdown">
          <view v-if="quote.record_no" class="line quote-reference">
            <text>{{ quote.can_submit ? '报价编号' : '试算编号' }}</text>
            <button class="inline-action accent" @tap="copyQuoteReference(quote.record_no)">复制编号</button>
          </view>
          <text v-if="quote.record_no" class="description quote-reference-value">{{ quote.record_no }}</text>
          <text v-if="quote.can_submit && quote.expires_at" class="description">
            {{ quoteExpired ? '锁汇已过期' : '汇率有效至 ' + formatRemitTimestamp(quote.expires_at * 1000, 'time') }}
          </text>
          <button v-else-if="quote.record_no" class="inline-action accent" :disabled="busy" @tap="saveDraft(quote.record_no)">保存为草稿</button>
        </view>
        <text v-if="quote?.mode === 'local_estimate'" class="description">试算报价不锁定汇率。</text>
        <view class="line session-quote-links">
          <button v-if="!quote" class="inline-action accent" hover-class="none" :disabled="busy" @tap="couponsOpen = true">
            {{ selectedCoupon ? `优惠券 · ${selectedCoupon.title}` : '我的优惠券' }}
          </button>
          <button class="inline-action accent" hover-class="none" :disabled="busy" @tap="inboxOpen = true">
            消息通知<text v-if="inboxCount" class="pill">{{ inboxCount }} 条未读</text>
          </button>
        </view>
      </view>
    </view>

    <view v-if="!member && props.allowLocalLogin" class="footer">
      <text v-if="error" class="hint offer" role="alert">{{ error }}</text>
      <button class="primary" :disabled="busy" @tap="login">{{ busy ? '请稍候' : '登录' }}</button>
    </view>
    <view v-if="showTabs" class="session-bottom">
      <view v-if="onHome" class="footer quote-footer">
        <text v-if="error" class="hint offer quote-feedback" role="alert">{{ error }}</text>
        <view class="line quote-footer-action">
          <view v-if="quote" class="home-savings">
            <text class="muted block">合计支付</text>
            <text class="price">€{{ formatAmount(quote.total_payable_minor) }}</text>
          </view>
          <button class="primary" hover-class="none" :disabled="busy" @tap="continueQuote">{{ quoteActionLabel }}</button>
        </view>
      </view>
      <view class="tabs" aria-label="主导航">
        <button
          v-for="tab in sessionTabs" :key="tab.key" class="tab" :class="{ active: activeTab === tab.key }"
          hover-class="none"
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
import { formatAmount, formatRemitTimestamp } from './previewOrder';
import RemitInbox from './RemitInbox.vue';
import RemitSupportConversation from './RemitSupportConversation.vue';
import RemitHelp from './RemitHelp.vue';
import { useSupportChat } from './useSupportChat';
import RemitCoupons from './RemitCoupons.vue';
import RemitCouponOffer from './RemitCouponOffer.vue';
import RemitDrafts, { type RemitDraft } from './RemitDrafts.vue';
import RemitOrders from './RemitOrders.vue';
import RemitOrderConfirmation from './RemitOrderConfirmation.vue';
import RemitMaterials from './RemitMaterials.vue';
import RemitTickets from './RemitTickets.vue';
import RemitKyc from './RemitKyc.vue';
import RemitClosure from './RemitClosure.vue';
import RemitPayoutDrafts from './RemitPayoutDrafts.vue';
import type { MemberCoupon } from './memberCoupon';
import { declarationError, emptyDeclaration, fundSources, transferPurposes, type RemittanceDeclaration } from './remittanceDeclaration';
import { pendingNotice, syncMemberPush } from './memberPush';
import { useRemitLoading } from './useRemitLoading';

interface QuoteEstimate {
  record_no: string;
  principal_minor: number;
  payment_method: 'sepa' | 'applePay' | 'card';
  payout_method: RemitDraft['payout_method'];
  mode: 'local_estimate' | 'locked';
  can_submit: boolean;
  simulated?: boolean;
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

interface PayoutAccount {
  reference: string;
  label: string;
}

interface MemberRemittanceSummary {
  monthly_sent_principal_minor: number;
  remaining_limit_minor: number | null;
}

const auth = new AuthRepository();
const props = defineProps<{
  pageVisible: boolean;
  allowLocalLogin: boolean;
  initialMember?: CurrentUser;
}>();
const member = ref<CurrentUser>();
const email = ref('');
const password = ref('');
const amount = ref('100');
const paymentOptions = [
  { value: 'sepa', label: 'SEPA 银行转账', icon: '€' },
  { value: 'applePay', label: 'Apple Pay', icon: '付' },
  { value: 'card', label: '借记卡 / 信用卡' },
] as const;
const paymentMethod = ref<QuoteEstimate['payment_method']>('sepa');
const paymentEditor = ref<QuoteEstimate['payment_method']>();
const payoutMethod = ref<'bank' | 'wechat'>('bank');
const quote = ref<QuoteEstimate>();
const quoteExpired = ref(false);
const quoteActionLabel = computed(() => {
  if (!quote.value) return '获取汇款报价';
  return quote.value.can_submit && !quoteExpired.value ? '开始汇款' : '重新报价';
});
const declaration = ref(emptyDeclaration());
const declarationEditor = ref<{ form: RemittanceDeclaration; quote?: QuoteEstimate }>();
const payoutSelection = ref<{ quote: QuoteEstimate; accounts: PayoutAccount[]; selected: string }>();
const confirmation = ref<{
  quote: QuoteEstimate;
  account: PayoutAccount;
  declaration: RemittanceDeclaration;
}>();
const busy = ref(false);
const inboxOpen = ref(false);
const quoting = ref(false);
const targetNotice = ref<string>();
const chatOpen = ref(false);
const helpOpen = ref(false);
const couponsOpen = ref(false);
const draftsOpen = ref(false);
const ordersOpen = ref(false);
const profileOpen = ref(false);
const accountSummary = ref<MemberRemittanceSummary>();
const accountSummaryLoading = ref(false);
const settingsOpen = ref(false);
const appVersion = computed(() => settingsOpen.value ? uni.getAppBaseInfo?.()?.appVersion : undefined);
const materialsOpen = ref(false);
const materialsView = ref<InstanceType<typeof RemitMaterials>>();
const payoutDraftsOpen = ref(false);
const payoutDraftsView = ref<InstanceType<typeof RemitPayoutDrafts>>();
const materialTarget = ref<string>();
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
  kycOpen.value || materialsOpen.value || payoutDraftsOpen.value || closureOpen.value || settingsOpen.value
  || Boolean(confirmation.value || payoutSelection.value || paymentEditor.value || declarationEditor.value),
);
const onHome = computed(() => Boolean(member.value) && !subPageOpen.value && !profileOpen.value);
const inboxVisible = computed(() => inboxOpen.value && !ordersOpen.value && !ticketsOpen.value && !materialsOpen.value);
useRemitLoading(() => quoting.value && onHome.value && props.pageVisible);
const onCouponHome = computed(() => onHome.value && props.pageVisible);
const showAccountSummary = computed(() => Boolean(member.value) && profileOpen.value && !subPageOpen.value && props.pageVisible);
const onOrderTab = computed(() => ordersOpen.value && !ticketsOpen.value && !inboxOpen.value && !orderDetail.value);
const showTabs = computed(() => Boolean(member.value) && (!subPageOpen.value || onOrderTab.value));
const showBack = computed(() => (subPageOpen.value && !onOrderTab.value) || (!member.value && !busy.value));
const usesFigmaSurface = computed(() => Boolean(
  !chatOpen.value && (paymentEditor.value || declarationEditor.value || payoutSelection.value || confirmation.value || kycOpen.value
    || payoutDraftsOpen.value || (ordersOpen.value && !ticketsOpen.value && orderView.value?.usesFigmaSurface)),
));
const usesReferenceSurface = computed(() => {
  if (usesFigmaSurface.value || chatOpen.value || ticketsOpen.value || closureOpen.value) return false;
  return ordersOpen.value || inboxOpen.value || materialsOpen.value || helpOpen.value || settingsOpen.value
    || (profileOpen.value && !subPageOpen.value);
});

const sessionTabs = [
  { key: 'home', label: '首页', icon: '/static/remit/tab-home.svg' },
  { key: 'orders', label: '订单', icon: '/static/remit/tab-orders.svg' },
  { key: 'profile', label: '我的', icon: '/static/remit/tab-profile.svg' },
] as const;
type SessionTab = (typeof sessionTabs)[number]['key'];
const activeTab = computed<SessionTab>(() => ordersOpen.value ? 'orders' : profileOpen.value ? 'profile' : 'home');

const pageTitle = computed(() => {
  if (paymentEditor.value) return '付款方式';
  if (declarationEditor.value) return '补充汇款信息';
  if (payoutDraftsOpen.value) return payoutDraftsView.value?.pageTitle ?? '本人收款资料';
  if (payoutSelection.value) return '人民币到账方式';
  if (confirmation.value) {
    return '确认并付款';
  }
  if (chatOpen.value) return '筷子闪汇客服';
  if (helpOpen.value) return '帮助与客服';
  if (couponsOpen.value) return '我的优惠券';
  if (draftsOpen.value) return '汇款草稿';
  if (ticketsOpen.value) return '客服工单';
  if (ordersOpen.value) return orderDetail.value ? orderView.value?.detailTitle ?? '订单详情' : '汇款订单';
  if (materialsOpen.value) return materialsView.value?.pageTitle ?? '补充资料';
  if (inboxOpen.value) return '消息通知';
  if (kycOpen.value) return '身份认证';
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
  {
    label: '本人收款资料',
    description: '管理待核验的银行卡和微信资料',
    icon: '/static/remit/bank-card.svg',
    open: () => { payoutDraftsOpen.value = true; },
  },
  {
    label: '我的优惠券',
    description: '领取优惠或兑换优惠码',
    icon: '/static/remit/coupon.svg',
    open: () => { couponsOpen.value = true; },
  },
  {
    label: '身份认证',
    description: '查询身份与汇款权限',
    icon: '/static/remit/identity.svg',
    open: () => { kycOpen.value = true; },
  },
  {
    label: '账户安全',
    description: '账户设置及服务协议',
    icon: '/static/remit/shield.svg',
    open: () => { settingsOpen.value = true; },
  },
  {
    label: '帮助与客服',
    description: '常见问题与客服留言',
    icon: '/static/remit/support.svg',
    open: () => { helpOpen.value = true; },
    badge: unread.value || undefined,
  },
  {
    label: '汇款草稿',
    description: '继续上次保存的试算',
    icon: '/static/remit/document.svg',
    open: () => { draftsOpen.value = true; },
  },
  {
    label: '补充资料',
    description: '查看要求和提交期限',
    icon: '/static/remit/document.svg',
    open: () => { materialsOpen.value = true; },
  },
  {
    label: '消息通知',
    description: '查看订单和服务消息',
    icon: '/static/remit/mail.svg',
    open: () => { inboxOpen.value = true; },
    badge: inboxCount.value || undefined,
  },
  {
    label: '客服工单',
    description: '提交问题或查看答复',
    icon: '/static/remit/document.svg',
    open: () => { ticketsOpen.value = true; },
  },
]);

const emit = defineEmits<{ back: []; terms: []; privacy: [] }>();

function selectTab(tab: SessionTab) {
  if (tab === activeTab.value) return;
  profileOpen.value = tab === 'profile';
  ordersOpen.value = tab === 'orders';
  orderDetail.value = false;
  returnOrder.value = undefined;
}

function startAnotherRemit() {
  inboxOpen.value = false;
  targetNotice.value = undefined;
  selectTab('home');
}

function leaveKycForHome() {
  kycOpen.value = false;
  selectTab('home');
}

function back() {
  if (paymentEditor.value || declarationEditor.value) {
    paymentEditor.value = undefined;
    declarationEditor.value = undefined;
    error.value = '';
  } else if (payoutDraftsOpen.value) {
    if (!payoutDraftsView.value?.back()) payoutDraftsOpen.value = false;
  } else if (payoutSelection.value) {
    payoutSelection.value = undefined;
    error.value = '';
  } else if (confirmation.value) {
    if (busy.value) {
      return;
    }
    if (!confirmation.value.quote.expires_at || confirmation.value.quote.expires_at * 1000 <= Date.now()) {
      quote.value = undefined;
    }
    confirmation.value = undefined;
    error.value = '';
  } else if (homeOffer.value && onCouponHome.value) {
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
  } else if (materialsOpen.value) {
    if (!materialsView.value?.back()) closeMaterials();
  } else if (inboxOpen.value) {
    inboxOpen.value = false;
    targetNotice.value = undefined;
  } else if (kycOpen.value) {
    kycOpen.value = false;
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

function closeMaterials() {
  materialsOpen.value = false;
  materialTarget.value = undefined;
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

watch(showAccountSummary, async (visible, _, onCleanup) => {
  const token = sessionService.getToken();
  if (!visible || !token) return;

  let active = true;
  onCleanup(() => { active = false; });
  const isCurrent = () => active && sessionService.getToken() === token;
  accountSummaryLoading.value = true;
  try {
    const summary = await request.get<MemberRemittanceSummary>('app/remittance/orders/summary', {}, { handleUnauthorized: false });
    if (isCurrent()) accountSummary.value = summary;
  } catch (reason) {
    if (!isCurrent()) return;
    accountSummary.value = undefined;
    if (reason instanceof UnauthorizedError) expire();
  } finally {
    if (isCurrent()) accountSummaryLoading.value = false;
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

function openNoticeMaterial(reference: string) {
  materialTarget.value = reference;
  materialsOpen.value = true;
}

watch([amount, paymentMethod, payoutMethod, selectedCoupon], () => {
  quoteRevision++;
  quote.value = undefined;
  confirmation.value = undefined;
  error.value = '';
}, { flush: 'sync' });

watch(quote, (result, _, onCleanup) => {
  quoteExpired.value = false;
  if (!result?.expires_at) return;
  const remaining = result.expires_at * 1000 - Date.now();
  if (remaining <= 0) {
    quoteExpired.value = true;
    return;
  }
  const timer = setTimeout(() => { quoteExpired.value = true; }, remaining);
  onCleanup(() => clearTimeout(timer));
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
  paymentEditor.value = undefined;
  declaration.value = emptyDeclaration();
  declarationEditor.value = undefined;
  payoutSelection.value = undefined;
  confirmation.value = undefined;
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
  accountSummary.value = undefined;
  accountSummaryLoading.value = false;
  settingsOpen.value = false;
  materialsOpen.value = false;
  payoutDraftsOpen.value = false;
  materialTarget.value = undefined;
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
    const match = /^(\d{1,9})(?:\.(\d{0,2}))?$/.exec(amount.value);
    const principal = match ? Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0')) : 0;
    if (principal < 10_000 || principal > 99_999_999_900) {
      quote.value = undefined;
      throw new Error('请输入有效金额，最低 €100，最多两位小数。');
    }
    const revision = quoteRevision;
    quoting.value = true;
    try {
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
    } catch (reason) {
      if (revision === quoteRevision) quote.value = undefined;
      throw reason;
    } finally {
      quoting.value = false;
    }
  });
}

function continueQuote() {
  const result = quote.value;
  if (result?.can_submit && result.expires_at && result.expires_at * 1000 > Date.now()) {
    return confirmOrder(result);
  }
  return estimate();
}

function choosePayout(labels: string[]): Promise<number | undefined> {
  return new Promise(resolve => uni.showActionSheet({
    itemList: labels,
    success: ({ tapIndex }) => resolve(tapIndex),
    fail: () => resolve(undefined),
  }));
}

async function changePayoutMethod() {
  const methods = [
    { label: '银行卡收款', value: 'bank' },
    { label: '微信收款', value: 'wechat' },
  ] as const;
  const index = await choosePayout(methods.map(method => method.label));
  const selected = index === undefined ? undefined : methods[index];
  if (selected) payoutMethod.value = selected.value;
}

function savePaymentMethod() {
  if (!paymentEditor.value) return;
  paymentMethod.value = paymentEditor.value;
  paymentEditor.value = undefined;
}

function openDeclaration(locked?: QuoteEstimate) {
  error.value = '';
  declarationEditor.value = { form: { ...declaration.value }, quote: locked };
}

function setDeclarationChoice(field: 'purpose' | 'source_of_funds', value: string) {
  if (!declarationEditor.value) return;
  declarationEditor.value.form[field] = value;
  error.value = '';
}

function saveDeclaration() {
  const editor = declarationEditor.value;
  if (!editor) return;
  const problem = declarationError(editor.form);
  if (problem) {
    error.value = problem;
    return;
  }
  declaration.value = editor.form;
  declarationEditor.value = undefined;
  error.value = '';
  if (editor.quote) void confirmOrder(editor.quote);
}

function confirmOrder(locked: QuoteEstimate) {
  return perform(async () => {
    if (!locked.expires_at || locked.expires_at * 1000 <= Date.now()) {
      throw new Error('锁汇已过期，请重新报价');
    }
    if (declarationError(declaration.value)) {
      openDeclaration(locked);
      return;
    }
    const options = { handleUnauthorized: false };
    const accounts = await request.get<PayoutAccount[]>(
      'app/remittance/payout-accounts', { payout_method: payoutMethod.value }, options,
    );
    if (quote.value?.record_no !== locked.record_no) return;
    if (locked.expires_at * 1000 <= Date.now()) {
      throw new Error('锁汇已过期，请重新报价');
    }
    const current = confirmation.value?.account.reference;
    payoutSelection.value = {
      quote: locked,
      accounts,
      selected: accounts.find(account => account.reference === current)?.reference ?? accounts[0]?.reference ?? '',
    };
  });
}

function confirmSelectedPayout() {
  return perform(async () => {
    const selection = payoutSelection.value;
    const account = selection?.accounts.find(item => item.reference === selection.selected);
    if (!selection || !account) return;
    const locked = selection.quote;
    if (quote.value?.record_no !== locked.record_no || !locked.expires_at || locked.expires_at * 1000 <= Date.now()) {
      throw new Error('锁汇已过期，请返回并重新报价');
    }
    confirmation.value = {
      quote: { ...locked }, account: { ...account },
      declaration: { ...declaration.value, explanation: declaration.value.explanation.trim() },
    };
    payoutSelection.value = undefined;
  });
}

function submitOrder() {
  return perform(async () => {
    const selected = confirmation.value;
    if (!selected || quote.value?.record_no !== selected.quote.record_no) {
      return;
    }
    const locked = selected.quote;
    if (locked.mode !== 'locked' || !locked.can_submit || !locked.expires_at || locked.expires_at * 1000 <= Date.now()) {
      throw new Error('报价已过期，请返回重新报价');
    }
    const order = await request.post<{ reference: string }>('app/remittance/orders', {
      quote_reference: locked.record_no, payout_account: selected.account.reference, declaration: selected.declaration,
    }, { handleUnauthorized: false });
    confirmation.value = undefined;
    returnOrder.value = order.reference;
    orderDetail.value = true;
    ordersOpen.value = true;
    selectedCoupon.value = null;
    quote.value = undefined;
    declaration.value = emptyDeclaration();
  });
}

function copyQuoteReference(reference: string) {
  uni.setClipboardData({ data: reference });
}

function saveDraft(reference: string) {
  return perform(async () => {
    await request.put(`app/remittance/drafts/${reference}`, { declaration: declaration.value }, { handleUnauthorized: false });
    uni.showToast({ title: '已保存汇款草稿', icon: 'none' });
  });
}

function resumeDraft(draft: RemitDraft) {
  amount.value = (draft.principal_minor / 100).toFixed(2);
  paymentMethod.value = draft.payment_method;
  payoutMethod.value = draft.payout_method;
  declaration.value = { ...emptyDeclaration(), ...draft.declaration };
  selectedCoupon.value = null;
  draftsOpen.value = false;
  profileOpen.value = false;
  void estimate();
}

onMounted(() => {
  if (!sessionService.getToken()) return;
  // 短信登录已从后端取得会员资料，只有从本地恢复的会话才需要重新校验。
  if (props.initialMember) {
    member.value = props.initialMember;
    return;
  }
  void perform(async () => {
    const profile = await auth.current();
    sessionService.setCurrentUser(profile);
    member.value = profile;
  });
});
</script>

<style scoped>
.remit.remit-session {
  height: 100vh;
  height: 100dvh;
  min-height: 0;
  overflow: hidden;
}
.session-content,
.session-home-layout {
  flex: 1;
  min-height: 0;
}
.session-content {
  overflow-y: auto;
  overscroll-behavior-y: contain;
}
.session-home-layout {
  display: flex;
  flex-direction: column;
}
.session-home-head {
  flex-shrink: 0;
  margin: 32rpx 40rpx 0;
}
.remit-home .session-home {
  padding-top: 0;
  padding-bottom: 28rpx;
}
.session-home > .promotion:first-child {
  margin-top: 20rpx;
}
.session-home-head .home-support[disabled],
.session-home .inline-action[disabled],
.session-home .quote-coupon-row[disabled],
.session-home .home-quote-option[disabled],
.session-top .top-action[disabled],
.session-bottom .tab[disabled] {
  background: transparent;
}
.session-home-head .home-support[disabled] {
  color: #000;
}
.session-home .home-quote-option[disabled] {
  color: #242733;
}
.session-home .quote-coupon-row[disabled] {
  color: inherit;
}
.session-home .inline-action.accent[disabled],
.session-bottom .tab.active[disabled] {
  color: var(--remit-accent);
}
.session-top .top-action[disabled],
.session-bottom .tab[disabled] {
  color: #8e8e8e;
}
.session-quote-costs {
  margin-inline: 4rpx;
}
.session-quote-costs .line {
  min-height: 62rpx;
}
.quote-fee-line {
  border-top: 1rpx solid #e7e7ef;
}
.quote-fee-values {
  flex-wrap: wrap;
  justify-content: flex-end;
}
.quote-coupon-row {
  width: 100%;
  padding: 0;
  background: transparent;
  color: inherit;
  font-size: inherit;
  text-align: left;
}
.quote-coupon-row .inline {
  min-width: 0;
  text-align: right;
  overflow-wrap: anywhere;
}
.quote-coupon-row .chevron {
  flex-shrink: 0;
}
.home-quote-options {
  display: grid;
  margin-top: 24rpx;
  padding: 0 24rpx;
}
.home-quote-option {
  justify-content: flex-start;
  gap: 14rpx;
  min-height: 80rpx;
  padding: 12rpx 0;
  background: transparent;
  color: #242733;
  font-size: 24rpx;
  text-align: left;
}
.home-quote-option + .home-quote-option { border-top: 1rpx solid #e7e7ef; }
.home-quote-option .quote-label {
  margin-right: auto;
  margin-bottom: 0;
}
.payment-editor .payout-selection-content,
.declaration-editor .payout-selection-content { padding: 32rpx 32rpx 0; }
.payment-editor .footer,
.declaration-editor .footer { padding-inline: 32rpx; }
.payment-editor .payout-selection-options { margin-top: 0; gap: 16rpx; }
.declaration-editor .payout-selection-options { margin-top: 18rpx; gap: 16rpx; }
.payment-editor .payout-selection-option { min-height: 129rpx; }
.payment-editor .payout-selection-label,
.declaration-sources .payout-selection-label { font-weight: 500; }
.payout-selection .payout-verified { color: #367d5c; }
.payment-editor .payout-selection-icon,
.declaration-editor .payout-selection-icon { flex-basis: 72rpx; height: 72rpx; border-radius: 24rpx; }
.selection-detail {
  display: block;
  margin-top: 6rpx;
  color: #6d7180;
  font-size: 22rpx;
  font-weight: 400;
}
.selection-caution {
  display: flex;
  align-items: center;
  box-sizing: border-box;
  padding: 16rpx 20rpx;
  border: 0;
  border-radius: 24rpx;
  background: #fff7f1;
  color: #83502c;
  font-size: 24rpx;
  line-height: 1.45;
}
.payment-caution { min-height: 118rpx; margin-top: 20rpx; }
.declaration-caution { min-height: 83rpx; }
.declaration-title { margin-top: 20rpx; }
.declaration-purposes { display: flex; flex-wrap: wrap; gap: 12rpx; margin-top: 16rpx; }
.purpose-option {
  min-width: 142rpx;
  min-height: 60rpx;
  padding: 8rpx 20rpx;
  border: 1rpx solid #e0e0ea;
  border-radius: 32rpx;
  background: #fff;
  color: #242733;
  font-size: 24rpx;
}
.purpose-option.is-selected { border-color: var(--remit-accent); background: #f0edff; color: var(--remit-accent); }
.declaration-explanation {
  box-sizing: border-box;
  width: 100%;
  min-height: 128rpx;
  height: 128rpx;
  margin-top: 16rpx;
  padding: 18rpx 20rpx;
  font-size: 26rpx;
}
.declaration-source-title { display: block; margin-top: 34rpx; font-size: 30rpx; font-weight: 700; }
.declaration-sources .payout-selection-option { min-height: 116rpx; padding-block: 16rpx; }
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
.remit-reference-surface .session-top {
  color: #171820;
  font-weight: 700;
}
.figma-session-top { border-color: transparent; background: var(--remit-light-surface); }
.profile-settings {
  position: absolute;
  inset: 0 12rpx 0 auto;
  width: 88rpx;
}
.profile-settings image {
  width: 32rpx;
  height: 32rpx;
}
.payout-selection {
  display: flex;
  flex: 1;
  flex-direction: column;
}
.payout-selection-content {
  padding: 32rpx 32rpx 0;
}
.payout-selection-note {
  display: flex;
  align-items: center;
  box-sizing: border-box;
  min-height: 118rpx;
  padding: 22rpx 26rpx;
  border: 0;
  border-radius: 32rpx;
  background: #edf8f2;
  color: #367d5c;
  font-size: 24rpx;
}
.payout-section-title {
  display: block;
  margin: 30rpx 0 18rpx;
  color: #666;
  font-size: 24rpx;
}
.payout-selection-options {
  display: grid;
  gap: 20rpx;
}
.payout-selection-option {
  display: flex;
  align-items: center;
  gap: 24rpx;
  width: 100%;
  min-height: 144rpx;
  padding: 22rpx 28rpx;
  color: #242733;
  text-align: left;
  line-height: 1.4;
}
.payout-selection-option.is-selected {
  border-color: var(--remit-accent);
  background: #f8f7ff;
}
.payout-selection > .footer { padding-bottom: max(72rpx, env(safe-area-inset-bottom)); }
.payout-management {
  margin-top: 20rpx;
  border-style: dashed;
  border-color: #bdb4ff;
  background: transparent;
  color: var(--remit-accent);
}
.payout-unavailable { opacity: .65; }
.payout-selection-icon {
  display: grid;
  place-items: center;
  flex: 0 0 88rpx;
  height: 88rpx;
  border-radius: 30rpx;
  background: #eeebff;
  color: var(--remit-accent);
  font-weight: 700;
}
.payout-selection-icon image {
  width: 46rpx;
  height: 46rpx;
}
.payout-selection-label {
  flex: 1;
  min-width: 0;
  font-size: 28rpx;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.payout-selection-mark {
  position: relative;
  box-sizing: border-box;
  flex: 0 0 36rpx;
  height: 36rpx;
  border: 2rpx solid #b8b9c6;
  border-radius: 50%;
}
.is-selected .payout-selection-mark {
  border: 10rpx solid var(--remit-accent);
}
.reference-back {
  display: grid;
  place-items: center;
  width: 70rpx;
  height: 70rpx;
  border-radius: 50%;
  background: #eff0f4;
}
.reference-back .chevron {
  transform: rotate(180deg);
  filter: brightness(0.35);
}
.chat-navigation-title {
  display: grid;
  justify-items: center;
  gap: 2rpx;
  line-height: 1.3;
}
.chat-navigation-status {
  color: #6d7180;
  font-size: 18rpx;
}
.chat-navigation-status.is-live {
  color: #18704d;
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
.session-quote-links > button:only-child {
  margin-left: auto;
}
.account-content {
  flex: 1;
}
.account-content:not(.settings-content) {
  padding-top: 60rpx;
}
.account-identity,
.account-link {
  display: flex;
  align-items: center;
  gap: 22rpx;
  padding: 26rpx 32rpx;
  border: 1.5rpx solid #e6e6ee;
}
.account-identity {
  min-height: 194rpx;
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
  width: 126rpx;
  height: 126rpx;
  border-radius: 50%;
  font-size: 40rpx;
}
.account-name {
  min-width: 0;
  font-size: 34rpx;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.account-remittance-summary {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  align-content: center;
  align-items: start;
  gap: 20rpx;
  box-sizing: border-box;
  min-height: 194rpx;
  margin-top: 24rpx;
  padding: 24rpx 38rpx;
  border-radius: 38rpx;
  background: linear-gradient(150deg, #6558f2, #4e3ee4);
  color: #fff;
}
.account-summary-column {
  display: grid;
  gap: 6rpx;
  min-width: 0;
}
.account-summary-column:last-child {
  text-align: right;
}
.account-summary-label,
.account-summary-note {
  color: #ece9ff;
  font-size: 24rpx;
}
.account-summary-note {
  font-size: 18rpx;
}
.account-summary-value,
.account-summary-status {
  font-weight: 700;
  overflow-wrap: anywhere;
}
.account-summary-value {
  font-size: 46rpx;
}
.account-summary-status {
  font-size: 30rpx;
}
.account-links {
  display: grid;
  gap: 20rpx;
  margin-top: 24rpx;
}
.account-link {
  width: 100%;
  min-height: 132rpx;
  color: #242733;
  font-size: 28rpx;
  font-weight: 600;
  line-height: 1.4;
  text-align: left;
}
.account-icon {
  width: 44rpx;
  height: 44rpx;
  padding: 20rpx;
  border-radius: 30rpx;
}
.account-link-text {
  display: grid;
  gap: 4rpx;
  min-width: 0;
}
.account-link-description {
  color: #6d7180;
  font-size: 22rpx;
  font-weight: 400;
  overflow-wrap: anywhere;
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
.settings-content {
  display: flex;
  flex-direction: column;
  padding-top: 64rpx;
}
.settings-content .account-section-title {
  font-weight: 600;
}
.settings-content .account-link {
  flex-shrink: 0;
  min-height: 144rpx;
  border-radius: 36rpx;
  box-shadow: 0 2rpx 4rpx rgba(31, 35, 48, 0.035);
}
.settings-content .account-link + .account-link {
  margin-top: 20rpx;
}
.legal-section-title {
  margin-top: 60rpx;
}
.settings-actions {
  display: grid;
  gap: 16rpx;
  margin-top: auto;
  padding: 48rpx 0 max(24rpx, env(safe-area-inset-bottom));
}
.account-logout {
  width: 100%;
  border: 1.5rpx solid #e6e6ee;
  border-radius: 32rpx;
  color: #b92e47;
  font-size: 28rpx;
  font-weight: 700;
}
.settings-version {
  color: #6d7180;
  font-size: 21rpx;
  text-align: center;
}
.session-bottom {
  z-index: 1;
  flex-shrink: 0;
  background: #fff;
  border-top: 1rpx solid #e7e7ef;
}
.session-bottom .quote-footer {
  position: relative;
  padding-bottom: 16rpx;
}
.quote-feedback {
  position: absolute;
  right: 0;
  bottom: 100%;
  left: 0;
  padding: 8rpx 32rpx;
  background: var(--remit-light-surface);
}
.quote-footer-action .home-savings {
  min-width: 0;
  overflow-wrap: anywhere;
}
.quote-footer-action .primary {
  flex: 1;
}
.quote-footer-action .primary[disabled] {
  opacity: 1;
}
.quote-footer-action .home-savings + .primary {
  flex: 0 0 338rpx;
}
.session-bottom .tabs {
  border-top: 1rpx solid #e7e7ef;
}
</style>
