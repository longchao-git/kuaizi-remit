// Local preview values. The service must supply the final rate, fees and order states.
export const previewRate = 80951;
export const referenceRate = 80120;
export const standardFee = 599;
export const discountedFee = 299;
export const aioFee = 190;
export const previewChannelRate = 8 / 1000;

export function getPreviewChannelFee(amount: number, channel: number) {
  return channel === 1 || channel === 2 ? Math.round(amount * previewChannelRate) : 0;
}

export function getSavings(amount: number, rate: number, fee: number) {
  return Math.round((amount * (rate - referenceRate) + (standardFee - fee) * rate) / 10000);
}

export function localDateKey(date = new Date()) {
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return `${date.getFullYear()}/${month}/${day}`;
}

export function getQuote(amount: string, fee: number, aio: boolean) {
  const [whole, fraction = ""] = amount.split(".");
  const valid = /^\d{1,9}(\.\d{0,2})?$/.test(amount);
  const cents = valid ? Number(whole) * 100 + Number(fraction.padEnd(2, "0")) : 0;
  const subtotal = cents + fee + (aio ? aioFee : 0);
  return {
    cents,
    fee,
    subtotal,
    received: Math.round((cents * previewRate) / 10000),
    savings: cents ? getSavings(cents, previewRate, fee) : 0,
    canStart: valid && cents >= 10000,
  };
}

export function formatAmount(cents: number) {
  return (cents / 100).toLocaleString("en-IE", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export const orderStages = {
  23: { status: "付款核验中", tone: "waiting", filter: "处理中" },
  24: { status: "汇款处理中", tone: "waiting", filter: "处理中" },
  25: { status: "专人跟进", tone: "waiting", filter: "处理中" },
  26: { status: "已到账", tone: "good", filter: "已完成" },
  27: { status: "待补件", tone: "warning", filter: "处理中" },
  29: { status: "资料审核中", tone: "waiting", filter: "处理中" },
  30: { status: "退款处理中", tone: "warning", filter: "退款" },
  31: { status: "已退款", tone: "warning", filter: "退款" },
} as const;

export type OrderStage = keyof typeof orderStages;
export interface PreviewOrder {
  id: string;
  account: string;
  date: string;
  stage: OrderStage;
  completedDate?: string;
  received: number;
  amount: number;
  fee: number;
  rate: number;
  total: number;
  aio: boolean;
  channel: number;
  purpose?: string;
  source?: string;
  attachment?: string;
}

export const nextStages: Partial<Record<OrderStage, readonly OrderStage[]>> = {
  23: [24],
  24: [26, 27],
  25: [27, 29],
  27: [29, 30],
  29: [26, 30],
  30: [31],
};

export function orderDestination(order: PreviewOrder) {
  return order.stage === 26 ? 33 : order.stage;
}

export function summarizeMonth(orders: readonly PreviewOrder[], date = localDateKey()) {
  const month = date.slice(0, 7);
  const summary = { sent: 0, saved: 0 };
  for (const order of orders) {
    if (order.stage !== 26 || !(order.completedDate ?? order.date).startsWith(month)) continue;
    summary.sent += order.amount;
    summary.saved += getSavings(order.amount, order.rate, order.fee);
  }
  return summary;
}
