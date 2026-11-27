export interface PrototypeAction {
  label: string;
  to: number;
}

export interface PrototypeScreen {
  title: string;
  heading?: string;
  description?: string;
  badge?: string;
  rows?: [string, string][];
  steps?: string[];
  activeStep?: number;
  options?: PrototypeAction[];
  actions?: PrototypeAction[];
  fields?: string[];
  mode?: "camera" | "upload" | "chat";
}

export const transferIban = "ES•• •••• •••• 2198";

export const screens: Record<number, PrototypeScreen> = {
  7: {
    title: "AIO 专人服务",
    heading: "AIO 专人服务 · €1.90",
    badge: "异常专人跟进",
    steps: [
      "本笔汇款可添加 AIO 专人服务",
      "跟进付款、审核和出款进度",
      "汇款异常时提供补件指引",
      "退款处理中协助查询进度",
    ],
    actions: [
      { label: "确认开启 · €1.90", to: 6 },
      { label: "不需要专人服务", to: 6 },
    ],
  },
  8: {
    title: "人民币到账方式",
    description: "仅支持汇入本人实名认证的账户。",
    actions: [{ label: "使用此到账方式", to: 10 }],
  },
  9: {
    title: "添加本人银行卡",
    heading: "新增人民币到账账户",
    fields: ["持卡人姓名", "开户银行", "银行卡号", "银行预留手机号"],
    actions: [{ label: "验证并保存", to: 8 }],
  },
  10: {
    title: "确认并付款",
    actions: [
      { label: "确认并继续", to: 12 },
      { label: "补充汇款信息", to: 11 },
    ],
  },
  11: {
    title: "补充汇款信息",
    heading: "请补充本笔用途",
    description: "请选择本次汇款的用途和资金来源。",
    actions: [{ label: "保存并继续", to: 12 }],
  },
  12: {
    title: "身份认证",
    heading: "完成认证即可汇款",
    description: "首次汇款需要验证身份。",
    steps: ["拍摄有效证件", "完成人脸识别", "等待审核结果"],
    activeStep: 0,
    actions: [{ label: "开始认证", to: 13 }],
  },
  13: {
    title: "选择证件",
    heading: "请选择正在使用的有效证件",
    description: "证件必须在有效期内，且信息清晰完整。",
    options: [
      { label: "本地居留卡 TIE", to: 14 },
      { label: "护照", to: 14 },
      { label: "欧盟身份证", to: 14 },
    ],
    actions: [{ label: "拍摄证件", to: 14 }],
  },
  14: {
    title: "拍摄证件正面",
    heading: "请将证件完整放入框内",
    description: "证件正面 · 避免反光并保持稳定",
    mode: "camera",
  },
  15: {
    title: "拍摄证件背面",
    heading: "请将证件完整放入框内",
    description: "证件背面 · 四角必须完整可见",
    mode: "camera",
  },
  16: {
    title: "人脸识别",
    heading: "请将面部对准识别框",
    description: "保持面部清晰，按提示完成识别。",
    mode: "camera",
    actions: [
      { label: "开始识别", to: 17 },
    ],
  },
  17: {
    title: "身份认证",
    heading: "资料审核中",
    description: "审核完成后可在消息中心查看结果。",
    rows: [
      ["认证资料", "已提交"],
      ["人脸识别", "已完成"],
      ["系统审核", "审核中"],
    ],
    actions: [
      { label: "预览认证通过", to: 18 },
      { label: "返回首页", to: 6 },
    ],
  },
  18: {
    title: "身份认证",
    heading: "认证已通过",
    description: "现在可以继续本次汇款。",
    rows: [
      ["核验姓名", "LIN **"],
      ["证件类型", "西班牙 TIE"],
      ["审核结果", "已通过"],
    ],
    actions: [{ label: "继续汇款", to: 20 }],
  },
  19: {
    title: "身份认证",
    heading: "暂时无法完成认证",
    description: "请检查证件信息是否清晰，或联系人工客服。",
    steps: ["确保证件在有效期内", "证件四角完整，无遮挡和反光", "在光线充足的环境下重试"],
    actions: [
      { label: "重新认证", to: 13 },
      { label: "联系人工客服", to: 36 },
    ],
  },
  20: {
    title: "付款方式",
    options: [
      { label: "SEPA 银行转账", to: 21 },
      { label: "Apple Pay", to: 22 },
      { label: "借记卡 / 信用卡", to: 22 },
    ],
    actions: [{ label: "继续付款", to: 21 }],
  },
  21: {
    title: "银行转账付款",
    description: "请准确填写付款附言，以便转账后核对订单。",
    rows: [
      ["收款人名称", "示例收款机构"],
      ["IBAN", transferIban],
      ["银行", "示例银行"],
      ["付款附言", ""],
    ],
    actions: [{ label: "我已完成转账", to: 23 }],
  },
  22: {
    title: "确认付款",
    heading: "请核对付款金额",
    actions: [
      { label: "预览付款结果", to: 23 },
      { label: "更换付款方式", to: 20 },
    ],
  },
  23: {
    title: "订单进度",
    heading: "正在确认你的付款",
    description: "核验完成后可在订单详情查看汇款进度。",
    rows: [
      ["订单编号", "KZ829104"],
      ["付款状态", "等待入账核验"],
    ],
    actions: [
      { label: "查看汇款进度", to: 24 },
      { label: "返回订单列表", to: 32 },
    ],
  },
  24: {
    title: "订单进度",
    badge: "汇款处理中",
    description: "预计汇入本人实名到账账户",
    steps: ["订单已创建", "已收到欧元付款", "欧元合规处理中", "人民币付款", "本人账户到账"],
    activeStep: 2,
    actions: [
      { label: "预览到账结果", to: 26 },
      { label: "查看补件要求", to: 27 },
    ],
  },
  25: {
    title: "AIO 异常处理",
    badge: "专人服务已开启",
    heading: "检测到汇款异常，专人管家已介入",
    description: "专人管家将协助跟进本次汇款。",
    rows: [
      ["专人管家", "Sophie"],
      ["当前处理", "合规审核"],
      ["预计更新", "今天"],
    ],
    actions: [
      { label: "联系专人管家", to: 29 },
      { label: "查看补件要求", to: 27 },
    ],
  },
  26: {
    title: "汇款结果",
    badge: "本人账户已到账",
    rows: [
      ["订单编号", "KZ829104"],
      ["汇款金额", ""],
      ["汇款手续费", "€2.99"],
      ["汇款汇率", "1 EUR = 8.0540 CNY"],
      ["汇款耗时", "27 分钟"],
    ],
    actions: [
      { label: "再次汇款", to: 6 },
      { label: "查看订单详情", to: 33 },
    ],
  },
  27: {
    title: "订单需要处理",
    heading: "需要补充资金来源证明",
    description: "请选择能证明本次汇款资金来源的文件。",
    steps: ["最近 3 个月的工资单", "显示工资入账的银行流水", "其他可证明资金来源的文件"],
    actions: [
      { label: "上传资料继续", to: 28 },
      { label: "申请原路退款", to: 30 },
    ],
  },
  28: {
    title: "上传资料",
    heading: "资金来源证明",
    description: "可提交 JPG、PNG 或 PDF 文件。",
    mode: "upload",
    actions: [{ label: "提交审核", to: 29 }],
  },
  29: {
    title: "补件进度",
    badge: "资料审核中",
    heading: "补件资料审核中",
    steps: ["已收到补件资料", "正在审核", "审核完成"],
    activeStep: 1,
    actions: [
      { label: "预览审核通过", to: 26 },
      { label: "联系客服", to: 36 },
      { label: "申请原路退款", to: 30 },
    ],
  },
  30: {
    title: "退款进度",
    heading: "退款已发起",
    description: "退款将原路退回付款账户。",
    rows: [
      ["退款金额", ""],
      ["退款账户", "BBVA · 2198"],
      ["退款状态", "处理中"],
      ["预计银行入账", "1–3 个工作日"],
    ],
    actions: [{ label: "查看退款结果", to: 31 }],
  },
  31: {
    title: "处理结果",
    badge: "退款完成",
    heading: "款项已原路退回",
    rows: [
      ["订单编号", "KZ829104"],
      ["退款账户", "BBVA · 2198"],
      ["退款状态", "已完成"],
    ],
    actions: [
      { label: "再次汇款", to: 6 },
      { label: "返回订单列表", to: 32 },
    ],
  },
  32: { title: "汇款订单" },
  33: {
    title: "订单详情",
    badge: "汇款已完成",
    description: "已汇入本人实名到账账户",
    rows: [
      ["订单编号", "KZ829104"],
      ["汇款金额", ""],
      ["汇款汇率", "1 EUR = 8.0540 CNY"],
      ["汇款手续费", "€2.99"],
      ["到账方式", ""],
    ],
    actions: [
      { label: "联系客服", to: 36 },
      { label: "再次汇款", to: 6 },
    ],
  },
  34: { title: "消息通知" },
  35: { title: "帮助与客服", heading: "汇款遇到问题？" },
  36: { title: "筷子闪汇客服", mode: "chat" },
  37: { title: "我的" },
  38: { title: "设置", actions: [{ label: "退出登录", to: 2 }] },
  39: {
    title: "绑定本人微信",
    heading: "绑定本人微信收款",
    description: "在微信官方小程序完成本人绑定后，返回此处确认结果。",
    steps: ["打开微信官方小程序", "按提示完成实名收款绑定", "返回筷子闪汇确认绑定结果"],
    actions: [
      { label: "预览微信绑定", to: 39 },
      { label: "返回到账方式", to: 8 },
    ],
  },
};
