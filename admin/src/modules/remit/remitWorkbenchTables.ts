export type WorkbenchTableKey = 'pricing' | 'quotes' | 'templates' | 'coupons' | 'push' | 'ops'
export type WorkbenchRow = Record<string, string> & { id: string }

interface Field {
  key: string
  label: string
  readonly?: boolean
  options?: string[]
  validate?: (value: string) => boolean
}

interface Table {
  fields: Field[]
  editable?: boolean
  createLabel: string
}

const enabled = ['启用', '停用']
const percent = (value: string) => /^\d{1,3}(?:\.\d{1,2})?$/.test(value) && Number(value) <= 100
const euros = (value: string) => /^\d{1,9}(?:\.\d{1,2})?$/.test(value)
const positiveDays = (value: string) => /^[1-9]\d{0,2}$/.test(value) && Number(value) <= 365
const channel = ['全部渠道', '微信收款', '银行卡收款']

export const workbenchTables: Record<WorkbenchTableKey, Table> = {
  pricing: {
    editable: true,
    createLabel: '新增点差策略',
    fields: [
      { key: 'name', label: '策略名称' },
      { key: 'segment', label: '用户分层', options: ['全部用户', '新用户', '老用户'] },
      { key: 'spread', label: '点差（%）', validate: percent },
      { key: 'budget', label: '补贴预算上限（EUR）', validate: euros },
      { key: 'priority', label: '优先级', validate: value => /^\d{1,3}$/.test(value) },
      { key: 'status', label: '状态', options: enabled }
    ]
  },
  quotes: {
    createLabel: '',
    fields: [
      { key: 'id', label: '报价编号' },
      { key: 'order', label: '订单号' },
      { key: 'rate', label: '成交汇率' },
      { key: 'fee', label: '手续费' },
      { key: 'rule', label: '策略版本' },
      { key: 'time', label: '报价时间' }
    ]
  },
  templates: {
    editable: true,
    createLabel: '新建优惠券模板',
    fields: [
      { key: 'name', label: '模板名称' },
      { key: 'discount', label: '基础手续费减免（%）', validate: percent },
      { key: 'days', label: '有效期（天）', validate: positiveDays },
      { key: 'channel', label: '收款渠道', options: channel },
      { key: 'status', label: '状态', options: enabled }
    ]
  },
  coupons: {
    editable: true,
    createLabel: '发放演示优惠',
    fields: [
      { key: 'delivery', label: '发放方式', options: ['优惠码', '直接发券'] },
      { key: 'code', label: '兑换码', validate: value => /^[A-Za-z0-9-]{4,32}$/.test(value) },
      { key: 'template', label: '券模板' },
      { key: 'audience', label: '适用用户或人群' },
      { key: 'status', label: '状态', readonly: true }
    ]
  },
  push: {
    editable: true,
    createLabel: '新增提醒计划',
    fields: [
      { key: 'name', label: '计划名称' },
      { key: 'audience', label: '目标人群' },
      { key: 'sendAt', label: '发送时间（用户当地 HH:mm）', validate: value => /^(?:[01]\d|2[0-3]):[0-5]\d$/.test(value) },
      { key: 'message', label: '提醒文案' },
      { key: 'status', label: '计划状态', options: ['草稿', '启用', '暂停'] }
    ]
  },
  ops: {
    editable: true,
    createLabel: '新增运营配置',
    fields: [
      { key: 'name', label: '配置项' },
      { key: 'value', label: '配置值' },
      { key: 'status', label: '状态', options: enabled }
    ]
  }
}

export const initialWorkbenchRows: Record<WorkbenchTableKey, WorkbenchRow[]> = {
  pricing: [
    { id: 'SPREAD-01', name: '基础点差', segment: '全部用户', spread: '0.5', budget: '0', priority: '10', status: '启用' }
  ],
  quotes: [
    { id: 'Q-829104', order: 'KZ829104', rate: '8.0540', fee: '€2.99', rule: 'SPREAD-01 v1', time: '2026-11-12 14:32' }
  ],
  templates: [
    { id: 'TPL-01', name: '首单免手续费', discount: '100', days: '30', channel: '全部渠道', status: '启用' },
    { id: 'TPL-02', name: '手续费半价', discount: '50', days: '15', channel: '全部渠道', status: '启用' }
  ],
  coupons: [
    { id: 'CP-01', delivery: '优惠码', code: 'REMIT-DEMO', template: '手续费半价', audience: '全部用户', status: '未使用' },
    { id: 'CP-02', delivery: '优惠码', code: 'WELCOME-01', template: '首单免手续费', audience: 'CU10001', status: '已核销' }
  ],
  push: [
    { id: 'PUSH-01', name: '首单汇款提醒', audience: '完成认证未汇款用户', sendAt: '18:30', message: '可在筷子闪汇查看您的首单优惠。', status: '草稿' }
  ],
  ops: [
    { id: 'OPS-01', name: '首发市场', value: '西班牙', status: '启用' },
    { id: 'OPS-02', name: 'App 运营公告', value: '汇款进度可在订单详情查看。', status: '启用' }
  ]
}
