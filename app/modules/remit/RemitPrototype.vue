<template>
  <view v-if="content.mode === 'camera'" class="camera" :class="{ 'liveness-camera': screen === 16 }">
    <view class="scan-frame" :class="{ oval: screen === 16 }">
      <view v-if="screen === 16" class="face-outline" aria-hidden="true" />
      <view v-else class="sample-document" :class="{ reverse: screen === 15 }" aria-hidden="true">
        <view /><view /><view />
      </view>
    </view>
    <view v-if="screen === 16" class="line camera-status">
      <text>光线状态示意</text>
      <text>演示就绪</text>
    </view>
    <template v-else>
      <text class="block">{{ content.heading }}</text>
      <text class="camera-hint block">{{ content.description }}</text>
      <button class="shutter" aria-label="拍摄证件" @tap="captureDocument">
        <view aria-hidden="true" />
      </button>
      <text class="camera-caption">核验界面预览</text>
    </template>
  </view>

  <view v-else-if="screen === 21" class="transfer-preview">
    <text class="callout warning transfer-warning">转账界面演示，请勿向示例账户转账。</text>
    <view class="card transfer-card">
      <view class="line transfer-amount">
        <text class="muted">转账金额</text>
        <text class="value">{{ euro(quote.subtotal) }}</text>
      </view>
      <view v-for="[label, value] in details" :key="label" class="line">
        <text class="muted">{{ label }}</text>
        <text class="value" :class="{ accent: label === '付款附言' }">{{ rowValue(label, value) }}</text>
      </view>
    </view>
    <text class="transfer-tip">{{ content.description }}</text>
    <view class="transfer-tools">
      <button class="text-link" @tap="copyTransferText(transferIban)">复制 IBAN 示例</button>
      <button class="text-link" @tap="copyTransferText(orderId)">复制付款附言</button>
    </view>
  </view>

  <view
    v-else
    class="body prototype"
    :class="{
      'tab-body': screen === 32 || screen === 37,
      'bank-form': screen === 9,
      'chat-body': content.mode === 'chat',
      'document-preview': screen === 13,
      'wallet-preview': screen === 22 && payment === 1,
    }"
  >
    <view v-if="screen === 13" class="document-progress" aria-label="证件核验第 1 步，共 3 步">
      <view class="is-current" /><view /><view />
    </view>

    <view
      v-if="status || screen === 24 || screen === 29 || screen === 33"
      class="status-hero"
      :class="{ centered: screen !== 24 && screen !== 29 }"
    >
      <view v-if="status" class="status-icon" :class="status.tone">
        <image
          v-if="previewIcons[status.symbol]"
          class="status-symbol"
          :src="previewIcons[status.symbol]"
          mode="aspectFit"
        />
        <text v-else>{{ status.symbol }}</text>
      </view>
      <text v-if="content.badge" class="pill" :class="status?.tone">{{ content.badge }}</text>
      <text v-if="screen === 24 || screen === 26 || screen === 33" class="hero-amount"
        >¥{{ displayReceived }}</text
      >
      <text v-if="screen === 22" class="heading">本次将支付 {{ euro(paymentTotal) }}</text>
      <text v-else-if="screen === 31" class="heading">{{ euro(paymentTotal) }} 已原路退回</text>
      <text v-else-if="content.heading" class="heading">{{ content.heading }}</text>
      <text v-if="screen === 22" class="description">人民币到账金额预览为 ¥{{ received }}</text>
      <text v-if="content.description" class="description">{{ content.description }}</text>
    </view>
    <template v-else-if="screen !== 35">
      <text v-if="content.heading" class="heading">{{ content.heading }}</text>
      <text v-if="screen === 8" class="callout good">{{ content.description }}</text>
      <text v-else-if="screen === 28" class="description">可选择 {{ attachmentFormats }} 格式的资料。</text>
      <text v-else-if="content.description" class="description">{{ content.description }}</text>
    </template>

    <template v-if="screen === 8">
      <text class="section-label">本人实名到账账户</text>
      <view v-for="account in accounts" :key="account" class="account-item">
        <button
          role="button"
          tabindex="0"
          class="tile"
          :class="{ selected: payout === account }"
          :disabled="account === '支付宝'"
          @tap="$emit('select-payout', account)"
        >
          <text class="tile-icon">{{ account[0] }}</text>
          <view class="tile-copy"
            ><text>{{ account }}</text
            ><text class="muted">{{
              account === "支付宝" ? "暂未开放" : account === "微信收款" && !wechatBound
                ? "待绑定本人微信"
                : "本人实名 · 人民币到账"
            }}</text></view
          >
          <text class="radio" :class="{ checked: payout === account }" />
        </button>
        <button
          role="button"
          tabindex="0"
          v-if="account === accounts[accounts.length - 2]"
          class="tile add-tile"
          @tap="$emit('navigate', 9)"
        >
          <text class="tile-icon">＋</text><text class="tile-copy">添加本人银行卡</text>
        </button>
      </view>
    </template>

    <template v-if="screen === 10">
      <view class="card prototype-card summary">
        <view class="line"><text class="muted">汇出</text><text class="muted">预计到账</text></view>
        <view class="line"
          ><text>{{ euro(quote.cents) }}</text
          ><text class="muted">→</text><text class="accent">¥{{ received }}</text></view
        >
        <text class="callout">优惠汇率 · {{ rateLabel(rate) }}</text>
      </view>
      <button role="button" tabindex="0" class="tile" @tap="$emit('navigate', 8)">
        <text class="tile-icon">{{ payout[0] }}</text
        ><view class="tile-copy"
          ><text>到账 · {{ payout }}</text
          ><text class="muted">本人实名账户</text></view
        ><text class="muted">›</text>
      </button>
      <view class="tile">
        <text class="tile-icon">€</text
        ><view class="tile-copy"><text>付款方式</text><text class="muted">下一步选择付款通道</text></view>
      </view>
    </template>

    <view v-if="screen === 10 || screen === 20 || screen === 22" class="card prototype-card">
      <view class="line"
        ><text>汇款金额</text><text>{{ euro(quote.cents) }}</text></view
      >
      <view class="line"
        ><text>汇款手续费</text><text>€{{ fee }}</text></view
      >
      <view v-if="aio" class="line"><text>AIO 专人服务</text><text>€1.90</text></view>
      <view v-if="payment > 0 && (screen === 20 || screen === 22)" class="line"
        ><text>{{ payment === 1 ? "Apple Pay" : "银行卡" }}通道费</text>
        <text :class="{ 'wallet-channel-fee': screen === 22 && payment === 1 }">{{ euro(channelFee) }}</text></view
      >
      <view class="line total"
        ><text>合计支付</text
        ><text>{{ euro(screen === 10 ? quote.subtotal : paymentTotal) }}</text></view
      >
    </view>

    <view v-if="details.length" class="card prototype-card">
      <view v-for="[label, value] in details" :key="label" class="line">
        <text class="muted">{{ label }}</text
        ><text class="value">{{ rowValue(label, value) }}</text>
      </view>
    </view>

    <view v-if="content.fields" class="card prototype-card">
      <view v-for="label in content.fields" :key="label" class="field">
        <text>{{ label }}</text
        ><input v-model="fields[label]" :placeholder="'请输入' + label" :aria-label="label" />
      </view>
    </view>

    <template v-if="screen === 11">
      <view class="chips">
        <button
          role="button"
          tabindex="0"
          v-for="item in purposes"
          :key="item"
          :class="{ selected: purpose === item }"
          @tap="purpose = item"
        >
          {{ item }}
        </button>
      </view>
      <text class="section-label">资金来源</text>
      <button
        role="button"
        tabindex="0"
        v-for="item in sources"
        :key="item"
        class="tile"
        :class="{ selected: source === item }"
        @tap="source = item"
      >
        <text class="tile-icon">{{ item[0] }}</text
        ><text class="tile-copy">{{ item }}</text
        ><text class="radio" :class="{ checked: source === item }" />
      </button>
    </template>

    <text v-if="formError && (screen === 9 || screen === 11)" class="callout warning" role="alert">{{ formError }}</text>
    <text v-if="screen === 22" class="callout warning">付款确认演示，继续后可预览付款核验状态。</text>
    <view v-if="steps" class="card prototype-card" :class="{ 'linked-timeline': screen === 24 || screen === 29 }">
      <view
        v-for="(step, i) in steps"
        :key="step"
        class="timeline"
        :class="{ active: i === activeStep, done: i < (activeStep ?? 0) }"
      >
        <text class="step-number">{{ i < (activeStep ?? 0) ? "✓" : i + 1 }}</text
        ><text>{{ step }}</text>
      </view>
    </view>

    <view v-if="screen === 13 || screen === 20">
      <button
        role="button"
        tabindex="0"
        v-for="(option, i) in content.options"
        :key="option.label"
        class="tile"
        :class="{ selected: (screen === 13 ? documentType : payment) === i }"
        :aria-pressed="(screen === 13 ? documentType : payment) === i"
        @tap="selectOption(i)"
      >
        <view class="tile-icon">
          <image
            v-if="screen === 20 && i !== 1"
            class="tile-symbol"
            :src="i === 0 ? previewIcons.bank : previewIcons['卡']"
            mode="aspectFit"
          />
          <text v-else>{{ (screen === 13 ? documentIcons : paymentIcons)[i] }}</text>
        </view>
        <view class="tile-copy">
          <text>{{ option.label }}</text>
          <text v-if="screen === 13" class="muted">{{ documentDescriptions[i] }}</text>
          <template v-else>
            <text class="muted">{{ paymentDetails[i]?.fee }} 通道费</text>
            <text class="payment-option-total" :class="{ 'is-selected': payment === i }">
              合计支付 {{ paymentDetails[i]?.total }}
            </text>
          </template>
        </view>
        <text class="radio" :class="{ checked: (screen === 13 ? documentType : payment) === i }" />
      </button>
    </view>
    <view v-if="screen === 13" class="document-tip">
      <text class="block">拍摄小提示</text>
      <text>请在光线充足的环境拍摄，避免反光和遮挡，确保证件四角完整。</text>
    </view>

    <text v-if="screen === 20" class="callout warning">
      Apple Pay 与卡支付通常另收汇款金额的 {{ channelRateLabel }}，最终金额以付款前展示为准。
    </text>

    <view v-if="content.mode === 'upload'" class="upload">
      <text v-if="attachmentError" class="callout" role="alert">{{ attachmentError }}</text>
      <view v-if="attachment" class="tile selected">
        <text class="tile-icon">{{ attachment.kind }}</text
        ><view class="tile-copy"
          ><text>{{ attachment.name }}</text
          ><text class="muted">{{ attachment.size }}</text></view
        ><button role="button" tabindex="0" class="text-link" @tap="attachment = null">移除文件</button>
      </view>
      <template v-else>
        <button role="button" tabindex="0" class="tile add-tile" @tap="chooseAttachment">
          <text class="tile-icon">＋</text>
          <view class="tile-copy"
            ><text>选择本地资料</text><text class="muted">{{ attachmentFormats }} · 最大 10 MB</text></view
          >
        </button>
        <button role="button" tabindex="0" class="text-link" @tap="useSampleAttachment">选择示例文件</button>
      </template>
      <view class="card prototype-card">
        <text class="section-label">上传前请确认</text>
        <view v-for="item in uploadChecks" :key="item" class="timeline"
          ><text class="step-number">✓</text><text>{{ item }}</text></view
        >
      </view>
    </view>

    <template v-if="screen === 32">
      <view class="chips">
        <button
          role="button"
          tabindex="0"
          v-for="filter in orderFilters"
          :key="filter"
          :class="{ selected: orderFilter === filter }"
          @tap="orderFilter = filter"
        >
          {{ filter }}
        </button>
      </view>
      <button
        role="button"
        tabindex="0"
        v-for="order in filteredOrders"
        :key="order.id"
        class="order-card"
        @tap="$emit('open-order', order.id)"
      >
        <text class="pill" :class="orderStages[order.stage].tone">{{ orderStages[order.stage].status }}</text>
        <view class="line"
          ><text>{{ order.account }}</text
          ><text class="price">¥{{ formatAmount(order.received) }}</text></view
        >
        <text class="muted">{{ order.id }} · {{ order.date }}</text>
      </button>
    </template>

    <text v-if="screen === 32 && !filteredOrders.length" class="description">暂无该状态的汇款订单</text>

    <template v-if="screen === 34">
      <view v-for="group in notificationGroups" :key="group.label">
        <text class="section-label">{{ group.label }}</text>
        <button
          role="button"
          tabindex="0"
          v-for="item in group.items"
          :key="item.id"
          class="tile"
          @tap="openNotification(item)"
        >
          <view class="tile-icon" :class="item.tone">
            <image
              v-if="previewIcons[item.icon]"
              class="tile-symbol"
              :src="previewIcons[item.icon]"
              mode="aspectFit"
            />
            <text v-else>{{ item.icon }}</text>
          </view>
          <view class="tile-copy">
            <text>{{ item.title }}</text>
            <text class="muted">{{ item.order ? item.order + " · " : "" }}{{ item.description }}</text>
          </view>
          <text v-if="!readNotifications.includes(item.id)" class="unread" />
        </button>
      </view>
    </template>

    <template v-if="screen === 35">
      <view class="support-banner">
        <text class="heading">汇款遇到问题？</text>
        <text class="block">筷子闪汇客服为您提供协助</text>
        <button role="button" tabindex="0" class="secondary" @tap="$emit('navigate', 36)">联系筷子闪汇客服</button>
      </view>
      <view class="tile search-field"
        ><input v-model="faqQuery" placeholder="搜索问题" aria-label="搜索常见问题"
      /></view>
      <text class="section-label">常见问题</text>
      <view v-for="item in filteredFaq" :key="item.title">
        <button
          role="button"
          tabindex="0"
          class="tile"
          @tap="activeQuestion = activeQuestion === item.title ? '' : item.title"
        >
          <text class="tile-icon">{{ item.icon }}</text
          ><text class="tile-copy">{{ item.title }}</text
          ><text class="muted">{{ activeQuestion === item.title ? "⌄" : "›" }}</text>
        </button>
        <text v-if="activeQuestion === item.title" class="answer">{{ item.answer }}</text>
      </view>
      <text v-if="!filteredFaq.length" class="description">暂无匹配问题</text>
    </template>

    <template v-if="content.mode === 'chat'">
      <text class="chat-online">{{ serverChat ? (serverChatLive ? '实时更新' : '客服消息') : '● 客服会话演示' }}</text>
      <text v-if="serverChat && serverChatStatus" class="description">{{ serverChatStatus }}</text>
      <button v-if="serverChat && (!serverChatLive || serverChatStatus)" role="button" tabindex="0" class="text-link chat-refresh" @tap="$emit('refresh-chat')">刷新消息</button>
      <scroll-view class="chat-scroll" scroll-y :scroll-into-view="chatScrollTarget">
        <view class="chat">
          <template v-if="serverChat">
            <text v-for="message in serverMessages" :id="`chat-message-${message.id}`" :key="message.id" class="bubble" :class="{ own: message.sender === 'member' }">{{ message.body }}</text>
          </template>
          <template v-else>
            <text v-if="order" class="section-label">关联订单 {{ order.id }}</text>
            <text class="bubble">您好，这里是筷子闪汇客服，请问有什么可以帮您？</text>
            <text v-for="(message, i) in messages" :id="`chat-demo-${i}`" :key="i" class="bubble own">{{ message }}</text>
          </template>
        </view>
      </scroll-view>
    </template>

    <template v-if="screen === 37">
      <view class="tile profile">
        <text class="avatar">林</text
        ><view class="tile-copy"
          ><text>LIN ••••</text><text class="muted">+34 {{ phone }}</text></view
        >
      </view>
      <view class="support-banner profile-stats line">
        <view
          ><text class="block">本月汇出</text
          ><text class="price">€{{ formatAmount(monthlySummary.sent) }}</text></view
        >
        <view
          ><text class="block">本月节省</text
          ><text class="price">¥{{ formatAmount(monthlySummary.saved) }}</text></view
        >
      </view>
      <button
        role="button"
        tabindex="0"
        v-for="item in profileItems"
        :key="item.label"
        class="tile"
        @tap="openSetting(item)"
      >
        <view class="tile-icon">
          <image
            v-if="item.icon && previewIcons[item.icon]"
            class="tile-symbol"
            :src="previewIcons[item.icon]"
            mode="aspectFit"
          />
          <text v-else>{{ item.icon }}</text>
        </view>
        <view class="tile-copy"
          ><text>{{ item.label }}</text
          ><text class="muted">{{ item.description }}</text></view
        ><text v-if="item.screen === 34 && unreadCount" class="pill">{{ unreadCount }} 条未读</text
        ><text class="muted">›</text>
      </button>
    </template>

    <template v-if="screen === 38">
      <text class="section-label">账户与安全</text>
      <button
        role="button"
        tabindex="0"
        v-for="item in accountSettings"
        :key="item.label"
        class="tile"
        @tap="openSetting(item)"
      >
        <text class="tile-icon">{{ item.icon }}</text
        ><view class="tile-copy"
          ><text>{{ item.label }}</text
          ><text class="muted">{{ item.description }}</text></view
        ><text class="muted">›</text>
      </button>
      <text class="section-label">服务与法律</text>
      <button
        role="button"
        tabindex="0"
        v-for="item in legalSettings"
        :key="item.label"
        class="tile"
        @tap="openSetting(item)"
      >
        <view class="tile-copy"
          ><text>{{ item.label }}</text
          ><text class="muted">{{ item.description }}</text></view
        ><text class="muted">›</text>
      </button>
    </template>
  </view>

  <view v-if="content.mode === 'chat'" class="footer">
    <view class="line chat-input">
      <input v-model="messageInput" placeholder="输入消息" maxlength="500" aria-label="客服消息" @confirm="send" />
      <button role="button" tabindex="0" class="text-link" :disabled="serverChatSending || !messageInput.trim()" @tap="send">{{ serverChatSending ? '发送中' : '发送' }}</button>
    </view>
  </view>

  <view v-if="content.actions" class="footer" :class="{
    'preview-flow-footer': [13, 16, 21, 22].includes(screen),
    'liveness-footer': screen === 16,
    'wallet-preview-footer': screen === 22 && payment === 1,
  }">
    <button
      role="button"
      :tabindex="screen === 28 && !attachment ? -1 : 0"
      v-for="(action, index) in actions"
      :key="action.label"
      :class="actionClasses(index)"
      :disabled="screen === 28 && !attachment"
      @tap="follow(action.to, index)"
    >
      {{ index === 0 ? primaryLabel || action.label : action.label }}
    </button>
    <text v-if="screen === 21" class="hint">确认后将进入付款核验演示</text>
  </view>
</template>

<script setup lang="ts">
import { computed, onUnmounted, reactive, ref, watch } from "vue";
import { screens, transferIban } from "./prototypeScreens";
import {
  accountSettings,
  documentDescriptions,
  documentIcons,
  faq,
  legalSettings,
  paymentIcons,
  profileItems,
  statusIcons,
  uploadChecks,
} from "./previewContent";
import type { PreviewNotification, PreviewSetting } from "./previewContent";
import {
  formatAmount,
  getPreviewChannelFee,
  getQuote,
  localDateKey,
  orderStages,
  previewChannelRate,
  summarizeMonth,
} from "./previewOrder";
import type { OrderStage, PreviewOrder } from "./previewOrder";
import { attachmentFormats, pickAttachment } from "./previewAttachment";
import type { PreviewAttachment } from "./previewAttachment";

const props = defineProps<{
  screen: number;
  amount: string;
  received: string;
  payout: string;
  accounts: string[];
  phone: string;
  fee: string;
  aio: boolean;
  rate: number;
  orderId: string;
  order?: PreviewOrder;
  orders: PreviewOrder[];
  accountSettingsMode: boolean;
  readNotifications: string[];
  messages: string[];
  serverMessages: { id: number; sender: 'staff' | 'member'; body: string }[];
  serverChat: boolean;
  serverChatLive: boolean;
  serverChatStatus: string;
  serverChatSending: boolean;
  documentType: number;
  payment: number;
  identitySettings: boolean;
  notifications: PreviewNotification[];
  wechatBound: boolean;
}>();
const emit = defineEmits<{
  navigate: [screen: number];
  back: [];
  "select-payout": [account: string];
  "add-account": [account: string];
  "open-order": [id: string];
  "confirm-payout": [];
  "advance-order": [next: OrderStage, channel: number, attachment?: string];
  "order-information": [purpose: string, source: string];
  "read-notifications": [titles: string[]];
  "send-message": [message: string, onSent?: () => void];
  "refresh-chat": [];
  logout: [];
  "select-document": [index: number];
  "select-payment": [index: number];
  "verify-identity": [];
  "open-identity": [];
  "manage-coupon": [];
  "start-identity-review": [];
  "preview-wechat-binding": [];
  legal: [kind: "privacy"];
}>();

const content = computed(() => screens[props.screen]!);
const monthlySummary = computed(() => summarizeMonth(props.screen === 37 ? props.orders : []));
const unreadCount = computed(
  () => props.notifications.filter((item) => !props.readNotifications.includes(item.id)).length,
);
const notificationGroups = computed(() => {
  if (props.screen !== 34) return [];
  const date = localDateKey();
  const today: PreviewNotification[] = [];
  const earlier: PreviewNotification[] = [];
  for (const item of props.notifications) {
    (item.date === date ? today : earlier).push(item);
  }
  return [
    { label: "今天", items: today },
    { label: "更早", items: earlier },
  ].filter((group) => group.items.length > 0);
});
const previewIcons: Record<string, string> = {
  bank: "/static/remit/bank.svg",
  卡: "/static/remit/bank-card.svg",
  证: "/static/remit/identity.svg",
  盾: "/static/remit/shield.svg",
  信: "/static/remit/mail.svg",
  "▤": "/static/remit/document.svg",
};
const fields = reactive<Record<string, string>>({});
const formError = ref("");
const attachment = ref<PreviewAttachment | null>(null);
let attachmentRequest = 0;
const attachmentError = ref("");
const messageInput = ref("");
const latestChatMessage = computed(() => {
  if (props.serverChat) {
    const lastMessage = props.serverMessages[props.serverMessages.length - 1];
    return lastMessage ? `chat-message-${lastMessage.id}` : '';
  }
  return props.messages.length ? `chat-demo-${props.messages.length - 1}` : '';
});
const chatScrollTarget = ref('');
watch(latestChatMessage, (target) => { chatScrollTarget.value = target; }, { flush: 'post' });
const purpose = ref("");
const source = ref("");
const purposes = ["个人储蓄", "日常生活", "家庭支持", "其他"];
const sources = ["工资收入", "个人储蓄", "其他"];
const quote = computed(() => getQuote(props.amount, Math.round(Number(props.fee) * 100), props.aio));
const channelRateLabel = `${Number((previewChannelRate * 100).toFixed(2))}%`;
const channelFee = computed(() => getPreviewChannelFee(quote.value.cents, props.payment));
const displayReceived = computed(() => (props.order ? formatAmount(props.order.received) : props.received));
const paymentTotal = computed(
  () => props.order?.total ?? quote.value.subtotal + channelFee.value,
);
const paymentDetails = computed(() =>
  [0, 1, 2].map((channel) => {
    const fee = getPreviewChannelFee(quote.value.cents, channel);
    return { fee: euro(fee), total: euro(quote.value.subtotal + fee) };
  }),
);
const status = computed(() =>
  props.screen === 22 && props.payment === 2 ? { symbol: "卡", tone: "waiting" } : statusIcons[props.screen],
);
const activeStep = computed(() => content.value.activeStep);
const steps = computed(() => content.value.steps);
const actions = computed(() => content.value.actions);
const details = computed(() => {
  const rows = [...(content.value.rows ?? [])];
  const order = props.order;
  if (order && props.screen === 23) rows.push(["合计支付", euro(order.total)]);
  if (order && props.screen === 33) {
    rows.push(["付款方式", ["SEPA 银行转账", "Apple Pay", "借记卡 / 信用卡"][order.channel] ?? "—"]);
    rows.push(["合计支付", euro(order.total)]);
    if (order.purpose) rows.push(["汇款用途", order.purpose]);
    if (order.source) rows.push(["资金来源", order.source]);
  }
  if (props.screen === 29 && order?.attachment) rows.push(["已选资料", order.attachment]);
  return rows;
});
const primaryLabel = computed(() => {
  if (props.screen === 8 && props.accountSettingsMode) return "保存到账方式";
  if (props.screen === 18 && props.identitySettings) return "返回";
  if (props.screen === 20) {
    const channel = ["使用银行转账", "使用 Apple Pay", "使用银行卡"][props.payment];
    return channel + " · " + euro(paymentTotal.value);
  }
  if (props.screen === 22)
    return (props.payment === 1 ? "Apple Pay" : "银行卡付款") + " · " + euro(paymentTotal.value);
  return "";
});
const orderFilters = ["全部", "处理中", "已完成", "退款"];
const orderFilter = ref("全部");
const filteredOrders = computed(() =>
  props.orders.filter(
    (order) => orderFilter.value === "全部" || orderStages[order.stage].filter === orderFilter.value,
  ),
);
const faqQuery = ref("");
const activeQuestion = ref("");
const filteredFaq = computed(() =>
  faq.filter((item) => (item.title + item.answer).includes(faqQuery.value.trim())),
);

watch(
  () => props.screen,
  (next, previous) => {
    formError.value = "";
    if (previous === 28) attachmentRequest += 1;
    if (next === 28) {
      attachment.value = null;
      attachmentError.value = "";
    }
    if (previous === 9) {
      for (const field of Object.keys(fields)) delete fields[field];
    }
    if (next === 11 && previous === 10) {
      purpose.value = "";
      source.value = "";
    }
  },
);

onUnmounted(() => {
  attachmentRequest += 1;
});

function euro(cents: number) {
  return "€" + formatAmount(cents);
}

function rateLabel(rate: number) {
  return "1 EUR = " + (rate / 10000).toFixed(4) + " CNY";
}

function rowValue(label: string, value: string) {
  if (label === "订单编号" || label === "付款附言") return props.orderId;
  const order = props.order;
  if (label === "汇款金额") return euro(order?.amount ?? quote.value.cents);
  if (label === "退款金额") return euro(paymentTotal.value);
  if (label === "退款账户" && order?.channel === 1) return "Apple Pay 原付款卡";
  if (label === "退款账户" && order?.channel === 2) return "原付款银行卡";
  if (label === "汇款手续费") return euro(order?.fee ?? quote.value.fee);
  if (label === "到账方式") return order?.account ?? props.payout;
  if (label === "汇款汇率") return rateLabel(order?.rate ?? props.rate);
  if (label === "AIO 专人服务") return (order?.aio ?? props.aio) ? "€1.90" : "未使用";
  if (label === "证件类型") return screens[13]?.options?.[props.documentType]?.label || value;
  return value;
}

function selectOption(index: number) {
  if (props.screen === 13) emit("select-document", index);
  else emit("select-payment", index);
}

function actionClasses(index: number) {
  return {
    primary: index === 0,
    secondary: index > 0,
    inverted: props.screen === 16 && index === 0,
    "wallet-button": props.screen === 22 && props.payment === 1 && index === 0,
    "logout-button": props.screen === 38,
  };
}

function follow(next: number, index: number) {
  if (props.screen === 39 && index === 0) {
    emit("preview-wechat-binding");
    return;
  }
  if (props.screen === 8) {
    emit("confirm-payout");
    return;
  }
  if (props.screen === 9) {
    const bank = fields["开户银行"]?.trim();
    const number = fields["银行卡号"]?.replace(/\s/g, "");
    const mobile = fields["银行预留手机号"]?.replace(/\s/g, "");
    if (
      !bank ||
      !number ||
      !/^\d{12,19}$/.test(number) ||
      !fields["持卡人姓名"]?.trim() ||
      !mobile ||
      !/^(?:\+86)?1\d{10}$/.test(mobile)
    ) {
      formError.value = "请填写有效的银行卡资料";
      return;
    }
    emit("add-account", bank + " · " + number.slice(-4));
    for (const label of content.value.fields ?? []) delete fields[label];
    emit("back");
    return;
  }
  if (props.screen === 11) {
    if (!purpose.value || !source.value) {
      formError.value = "请选择汇款用途和资金来源";
      return;
    }
    emit("order-information", purpose.value, source.value);
  }
  if (props.screen === 18 && props.identitySettings) {
    emit("back");
    return;
  }
  if (props.screen === 17 && next === 18) {
    emit("verify-identity");
    return;
  }
  if (props.screen === 16 && next === 17) {
    emit("start-identity-review");
    return;
  }
  if (props.screen >= 21 && props.screen <= 30 && next in orderStages) {
    if (props.screen === 28 && !attachment.value) return;
    if (next === 30) {
      uni.showModal({
        title: "申请原路退款",
        content: "确认后，这笔演示订单将进入退款处理中。",
        success: (result) => {
          if (result.confirm) emit("advance-order", 30, props.payment);
        },
      });
      return;
    }
    emit(
      "advance-order",
      next as OrderStage,
      props.payment,
      props.screen === 28 ? attachment.value?.name : undefined,
    );
    return;
  }
  if (props.screen === 20 && index === 0) next = content.value.options?.[props.payment]?.to ?? next;
  if (props.screen === 38) {
    uni.showModal({
      title: "退出登录",
      content: "确认退出当前账户？",
      success: (result) => {
        if (result.confirm) emit("logout");
      },
    });
    return;
  }
  emit("navigate", next);
}

function captureDocument() {
  emit("navigate", props.screen === 14 && props.documentType !== 1 ? 15 : 16);
}

function useSampleAttachment() {
  attachmentRequest += 1;
  attachmentError.value = "";
  attachment.value = { name: "Nomina_Octubre.pdf", kind: "PDF", size: "示例文件" };
}

function chooseAttachment() {
  const order = props.orderId;
  const request = ++attachmentRequest;
  attachmentError.value = "";
  pickAttachment(
    (file) => {
      attachment.value = file;
    },
    () => request === attachmentRequest && props.screen === 28 && props.orderId === order,
    (message) => { attachmentError.value = message; },
  );
}

function copyTransferText(value: string) {
  uni.setClipboardData({
    data: value,
    fail: () => uni.showToast({ title: "复制失败，请重试", icon: "none" }),
  });
}

function openNotification(item: PreviewNotification) {
  emit("read-notifications", [item.id]);
  if (item.order) emit("open-order", item.order);
  else emit("open-identity");
}

function openSetting(item: PreviewSetting) {
  if (item.label === "我的优惠") {
    emit("manage-coupon");
    return;
  }
  if (item.label === "隐私政策与数据管理") {
    emit("legal", "privacy");
    return;
  }
  if (item.screen) {
    emit("navigate", item.screen);
    return;
  }
  uni.showModal({
    title: item.label,
    content: item.label === "手机号码" ? "+34 " + props.phone : item.description,
    showCancel: false,
  });
}

function send() {
  if (props.serverChatSending) return;
  const message = messageInput.value.trim();
  if (!message) return;
  if (props.serverChat) {
    emit("send-message", message, () => {
      if (messageInput.value.trim() === message) messageInput.value = "";
    });
    return;
  }
  emit("send-message", message);
  messageInput.value = "";
}
</script>

<style lang="scss" scoped src="./remit-prototype.scss"></style>
