export const modules = [
  { key: 'orders', label: '汇款订单', icon: 'List' },
  { key: 'customers', label: '汇款用户', icon: 'User' },
  { key: 'identity', label: 'KYC 状态', icon: 'DocumentChecked' },
  { key: 'recipients', label: '本人到账账户', icon: 'Wallet' },
  { key: 'documents', label: '补件管理', icon: 'DocumentChecked' },
  { key: 'support', label: '客服工单', icon: 'Service' },
  { key: 'reasons', label: '状态原因码', icon: 'List' }
] as const

export const previewReasons = [
  { id: 'PAYMENT_CHANNEL_PROCESSING', subject: '正在确认付款', guidance: '核对付款通道进度，提示客户勿重复付款。', status: '内置' },
  { id: 'KYC_PENDING_REVIEW', subject: '身份资料审核中', guidance: '查看上游认证状态，等待审核结果。', status: '内置' },
  { id: 'UPSTREAM_TIMEOUT', subject: '上游确认超时', guidance: '核查上游状态，再向客户反馈。', status: '内置' }
]

export const previewOrders = [
  { id: 'KZ829104', customer: 'LIN **', method: '微信收款', eur: '€1,000.00', cny: '¥8,054.00', funding: '已入账', payout: '已到账', refund: '—', status: '已完成', time: '2026-11-12 14:32' },
  { id: 'KZ829103', customer: 'WANG **', method: '银联 · 2198', eur: '€600.00', cny: '¥4,832.40', funding: '已入账', payout: '待补件', refund: '—', status: '待补件', time: '2026-11-12 13:48' },
  { id: 'KZ829102', customer: 'ZHANG **', method: '微信收款', eur: '€350.00', cny: '¥2,818.90', funding: '已入账', payout: '出款失败', refund: '已退款', status: '已退款', time: '2026-11-12 12:05' },
  { id: 'KZ829101', customer: 'LI **', method: '支付宝', eur: '€800.00', cny: '¥6,443.20', funding: '核验中', payout: '待出款', refund: '—', status: '付款核验中', time: '2026-11-12 11:26' },
  { id: 'KZ829100', customer: 'CHEN **', method: '银联 · 6331', eur: '€420.00', cny: '¥3,382.68', funding: '已入账', payout: '出款中', refund: '—', status: '处理中', time: '2026-11-12 10:53' }
]

export const previewCustomers = [
  { id: 'CU10001', customer: 'LIN **', phone: '+34 6•••••••8', document: '西班牙 TIE', status: '已认证', time: '2026-11-12 14:23' },
  { id: 'CU10002', customer: 'WANG **', phone: '+34 6•••••••3', document: '护照', status: '审核中', time: '2026-11-12 13:25' },
  { id: 'CU10003', customer: 'LI **', phone: '+34 6•••••••6', document: '西班牙 TIE', status: '待认证', time: '2026-11-12 11:02' },
  { id: 'CU10004', customer: 'ZHANG **', phone: '+34 6•••••••2', document: '护照', status: '未通过', time: '2026-11-12 12:30' }
]

export const previewIdentity = [
  { id: 'IDV-10001', customer: 'LIN **', user: 'CU10001', document: '西班牙 TIE', status: '已认证', updated: '2026-11-12 14:23', reason: '—' },
  { id: 'IDV-10002', customer: 'WANG **', user: 'CU10002', document: '护照', status: '审核中', updated: '2026-11-12 13:25', reason: '等待上游审核' },
  { id: 'IDV-10003', customer: 'ZHANG **', user: 'CU10004', document: '护照', status: '未通过', updated: '2026-11-12 12:30', reason: '证件图片不清晰' }
]

export const previewRecipients = [
  { id: 'RC10001', user: 'CU10001', customer: 'LIN **', method: '微信收款', account: '微信 · 已绑定', status: '已核验' },
  { id: 'RC10002', user: 'CU10001', customer: 'LIN **', method: '银行卡收款', account: '中国银行 · 2198', status: '已核验' },
  { id: 'RC10003', user: 'CU10002', customer: 'WANG **', method: '银行卡收款', account: '银联 · 2198', status: '待核验' }
]

export const previewDocuments = [
  { id: 'DOC102', order: 'KZ829103', customer: 'WANG **', document: '资金来源证明', deadline: '2026-11-15 18:00', assignee: '未分派', status: '待补件' },
  { id: 'DOC101', order: 'KZ829099', customer: 'LIN **', document: '银行流水', deadline: '2026-11-14 18:00', assignee: '运营组', status: '待审核' }
]

export const previewTickets = [
  { id: 'TK301', order: 'KZ829103', customer: 'WANG **', type: '普通客服', subject: '咨询资金来源证明', assignee: '运营组', status: '处理中' },
  { id: 'TK300', order: 'KZ829101', customer: 'LI **', type: '普通客服', subject: '咨询付款核验进度', assignee: '未分派', status: '待处理' },
  { id: 'TK299', order: 'KZ829102', customer: 'ZHANG **', type: '普通客服', subject: '查询退款结果', assignee: '客服组', status: '已关闭' }
]
