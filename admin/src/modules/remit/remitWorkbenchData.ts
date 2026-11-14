export const workbenchMenus = [
  { key: 'dashboard', label: '经营仪表盘' },
  { key: 'pricing', label: '定价与点差' },
  { key: 'quotes', label: '报价命中记录' },
  { key: 'profit', label: '毛利预估器' },
  { key: 'templates', label: '优惠券模板' },
  { key: 'coupons', label: '发券与核销' },
  { key: 'push', label: 'Push 提醒' },
  { key: 'finance', label: '对账与结算' },
  { key: 'ops', label: '运营配置' },
  { key: 'roles', label: '角色与权限' },
  { key: 'audit', label: '操作审计' }
] as const

export type WorkbenchKey = typeof workbenchMenus[number]['key']

export interface AuditRow {
  id: number
  time: string
  action: string
  target: string
  details: string
}

// 对账预览接收订单号和欧元金额两列。金额转成整数分后比较。
export function parseSettlement(text: string, amounts: ReadonlyMap<string, number>) {
  const lines = text.replace(/^\uFEFF/, '').trim().split(/\r?\n/)
  if (lines.shift()?.trim() !== 'order_id,amount_eur' || !lines.length) {
    throw new Error('请上传包含 order_id,amount_eur 表头和明细的 CSV')
  }
  const seen = new Set<string>()
  return lines.map((line, index) => {
    const match = /^([A-Za-z0-9_-]+),(\d{1,9}(?:\.\d{1,2})?)$/.exec(line.trim())
    if (!match) throw new Error(`第 ${index + 2} 行格式无效`)
    const [, order, value] = match
    if (seen.has(order)) throw new Error(`订单 ${order} 重复`)
    seen.add(order)
    const cents = Math.round(Number(value) * 100)
    const expected = amounts.get(order)
    return {
      order,
      imported: cents,
      expected,
      status: expected === undefined ? '未找到订单' : expected === cents ? '已匹配' : '金额差异'
    }
  })
}
