import { localDateKey } from "./previewOrder";
import type { OrderStage, PreviewOrder } from "./previewOrder";

export const orders: PreviewOrder[] = [
  {
    id: "KZ829104",
    stage: 26,
    account: "微信收款",
    received: 805400,
    date: "2026/11/12",
    amount: 100000,
    rate: 80540,
    fee: 299,
    total: 100299,
    aio: false,
    channel: 0,
  },
  {
    id: "KZ829103",
    stage: 27,
    account: "中国银行 · 2198",
    received: 483240,
    date: "2026/11/12",
    amount: 60000,
    rate: 80540,
    fee: 299,
    total: 60299,
    aio: false,
    channel: 0,
  },
  {
    id: "KZ829102",
    stage: 31,
    account: "微信收款",
    received: 281890,
    date: "2026/11/12",
    amount: 35000,
    rate: 80540,
    fee: 299,
    total: 35299,
    aio: false,
    channel: 0,
  },
];

interface NotificationContent {
  icon: string;
  tone: string;
  title: string;
  description: string;
}

export interface PreviewNotification extends NotificationContent {
  id: string;
  order: string;
  date: string;
}

export function createIdentityNotification(): PreviewNotification {
  return {
    id: "identity-approved",
    order: "",
    date: localDateKey(),
    icon: "证",
    tone: "good",
    title: "身份认证已通过",
    description: "可继续汇款或查看认证结果",
  };
}

const orderMessages: Partial<Record<OrderStage, NotificationContent>> = {
  25: { icon: "盾", tone: "waiting", title: "AIO 专人服务已介入", description: "管家将跟进本笔订单的审核进度" },
  26: { icon: "✓", tone: "good", title: "汇款已到账", description: "人民币已汇入本人实名到账账户" },
  27: { icon: "证", tone: "warning", title: "汇款需要补充资料", description: "请补充本笔汇款的资金来源证明" },
  29: { icon: "证", tone: "waiting", title: "补件资料已提交", description: "可查看本笔汇款的资料审核进度" },
  30: { icon: "↩", tone: "warning", title: "退款已发起", description: "可查看本笔汇款的原路退款进度" },
  31: { icon: "✓", tone: "good", title: "退款已完成", description: "款项已原路退回付款账户" },
};

export function createOrderNotification(order: string, stage: OrderStage, date = localDateKey()) {
  const content = orderMessages[stage];
  if (!content) return;
  return { ...content, id: order + ":" + stage, order, date };
}

export const notifications: PreviewNotification[] = [
  createOrderNotification("KZ829104", 26, orders[0]!.date)!,
  createOrderNotification("KZ829103", 27, orders[1]!.date)!,
];

export const faq = [
  { icon: "时", title: "汇款多久能到账？", answer: "报价页会显示预计到账时间，实际进度可在订单详情查看。" },
  {
    icon: "审",
    title: "如何补充资金来源证明？",
    answer: "打开待补件订单，进入上传资料，按页面提示提交对应文件。",
  },
  { icon: "费", title: "付款通道费用如何计算？", answer: "选择付款方式时可以查看费用明细和本笔合计付款金额。" },
];

export interface PreviewSetting {
  icon?: string;
  label: string;
  description: string;
  screen?: number;
}

export const profileItems: PreviewSetting[] = [
  { icon: "微", label: "我的人民币到账方式", description: "管理本人银行卡和微信", screen: 8 },
  { icon: "券", label: "我的优惠", description: "首笔汇款手续费优惠", screen: 5 },
  { icon: "证", label: "身份与汇款限额", description: "查看身份认证信息", screen: 12 },
  { icon: "信", label: "消息通知", description: "查看订单和服务消息", screen: 34 },
  { icon: "盾", label: "账户安全", description: "账户设置及服务协议", screen: 38 },
  { icon: "?", label: "帮助与客服", description: "汇款常见问题与在线客服", screen: 35 },
];

export const accountSettings: PreviewSetting[] = [
  { icon: "电", label: "手机号码", description: "当前账户登录手机号" },
  { icon: "脸", label: "Face ID 快捷登录", description: "Face ID 设置预览" },
  { icon: "机", label: "登录设备", description: "当前设备" },
  { icon: "盾", label: "反诈安全中心", description: "请仅使用本人账户汇款，勿向他人透露验证码。" },
];

export const legalSettings: PreviewSetting[] = [
  { label: "汇款服务协议", description: "查看汇款服务重要提示", screen: 4 },
  { label: "隐私政策与数据管理", description: "隐私政策预览" },
];

export const statusIcons: Partial<Record<number, { symbol: string; tone: string }>> = {
  12: { symbol: "证", tone: "waiting" },
  17: { symbol: "↻", tone: "waiting" },
  18: { symbol: "✓", tone: "good" },
  19: { symbol: "!", tone: "error" },
  22: { symbol: "Pay", tone: "waiting" },
  23: { symbol: "↻", tone: "waiting" },
  25: { symbol: "盾", tone: "good" },
  26: { symbol: "✓", tone: "good" },
  27: { symbol: "▤", tone: "warning" },
  30: { symbol: "↩", tone: "waiting" },
  31: { symbol: "✓", tone: "good" },
};

export const documentIcons = ["TIE", "护", "EU"];
export const paymentIcons = ["€", "Pay", "卡"];
export const uploadChecks = ["资料属于本人且真实有效", "文字清晰，无裁切或遮挡", "与本次汇款用途一致"];
