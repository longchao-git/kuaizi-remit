type LazyComp = () => Promise<unknown>

// 页面组件注册表：仅负责维护后端菜单 component 与前端页面的映射关系。
export const componentMap: Record<string, LazyComp> = {
  'remittance/rules': () => import('@/modules/remit/RemitRulesPage.vue'),
  'remittance/operations': () => import('@/modules/remit/RemitOperationsPage.vue'),
  'remittance/agreements': () => import('@/modules/remit/RemitAgreementsPage.vue'),
  'remittance/reconciliation': () => import('@/modules/remit/RemitReconciliationPage.vue'),
  'remittance/dashboard': () => import('@/modules/remit/RemitDashboardPage.vue'),
  'remittance/closures': () => import('@/modules/remit/RemitClosuresPage.vue'),
  'remittance/push': () => import('@/modules/remit/RemitPushPage.vue'),
  'remittance/quotes': () => import('@/modules/remit/RemitQuoteRecordsPage.vue'),
  'remittance/orders': () => import('@/modules/remit/RemitOrdersPage.vue'),
  'remittance/materials': () => import('@/modules/remit/RemitMaterialsPage.vue'),
  'remittance/members': () => import('@/modules/remit/RemitMembersPage.vue'),
  'remittance/coupons': () => import('@/modules/remit/RemitCouponsPage.vue'),
  'remittance/staff': () => import('@/modules/remit/RemitStaffPage.vue'),
  'remittance/audit': () => import('@/modules/remit/RemitAuditPage.vue'),
  'remittance/status-reasons': () => import('@/modules/remit/RemitStatusReasonsPage.vue'),
  'support/messages': () => import('@/modules/remit/RemitSupportPage.vue'),
  'support/tickets': () => import('@/modules/remit/RemitTicketsPage.vue')
}
