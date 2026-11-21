<template>
  <section v-if="!selected" class="orders-page">
    <h1>订单中心</h1>
    <div v-if="summary" class="order-summary" aria-label="筛选范围内的订单统计">
      <el-card v-for="[key, label] in summaryLabels" :key="key" shadow="never">
        <span>{{ label }}</span><strong>{{ summary[key] }}</strong>
      </el-card>
    </div>
    <el-form class="order-filters" inline @submit.prevent="search">
      <el-form-item label="订单编号"><el-input v-model.trim="reference" maxlength="36" clearable /></el-form-item>
      <el-form-item class="member-filter" label="会员编号">
        <el-input v-model.trim="member" maxlength="19" clearable />
      </el-form-item>
      <el-form-item label="订单状态">
        <el-select v-model="status" clearable placeholder="全部状态">
          <el-option v-for="[value, label] in statusLabels" :key="value" :label="label" :value="value" />
        </el-select>
      </el-form-item>
      <el-form-item class="payment-filter" label="付款渠道">
        <el-select v-model="payment" clearable placeholder="全部付款渠道">
          <el-option v-for="(label, value) in paymentLabels" :key="value" :label="label" :value="value" />
        </el-select>
      </el-form-item>
      <el-form-item label="收款渠道">
        <el-select v-model="payout" clearable placeholder="全部收款渠道">
          <el-option v-for="(label, value) in payoutLabels" :key="value" :label="label" :value="value" />
        </el-select>
      </el-form-item>
      <el-form-item>
        <el-button native-type="submit" type="primary" :loading="busy">查询</el-button>
        <el-button :disabled="busy" @click="reset">重置</el-button>
      </el-form-item>
    </el-form>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-card shadow="never" class="order-list remit-card">
      <template #header><h2>记录列表</h2></template>
      <el-table
        v-loading="busy && !syncing"
        :data="rows"
        :empty-text="error ? '读取失败' : '暂无汇款订单'"
        max-height="max(300px, calc(100vh - 350px))"
        scrollbar-always-on
      >
        <el-table-column label="订单号 / 用户" min-width="230">
          <template #default="{ row }">
            <el-button link type="primary" class="order-reference" @click="openDetail(row.reference)">
              {{ row.reference }}
            </el-button>
            <div class="order-secondary">会员 {{ row.member_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" min-width="146">
          <template #default="{ row }">{{ dateTime.format(new Date(row.created_at)) }}</template>
        </el-table-column>
        <el-table-column label="汇款本金" min-width="106">
          <template #default="{ row }">{{ currency.format(row.principal_minor / 100) }}</template>
        </el-table-column>
        <el-table-column label="预计到账" min-width="106">
          <template #default="{ row }">{{ amount(row.quote?.payout_minor, yuan) }}</template>
        </el-table-column>
        <el-table-column label="手续费" min-width="90">
          <template #default="{ row }">{{ amount(serviceFee(row.quote), currency) }}</template>
        </el-table-column>
        <el-table-column label="状态 / ETA" min-width="145">
          <template #default="{ row }">
            <el-tag :type="statusTone(row.status_label)" effect="light">{{ row.status_label }}</el-tag>
            <div v-if="row.quote?.eta" class="order-secondary">{{ row.quote.eta }}</div>
          </template>
        </el-table-column>
        <el-table-column label="付款 / 收款" min-width="132">
          <template #default="{ row }">
            {{ paymentLabels[row.payment_method] || '—' }}
            <div class="order-secondary">{{ payoutLabels[row.payout_method] || '—' }}</div>
          </template>
        </el-table-column>
        <el-table-column v-if="canSync" label="操作" width="104" fixed="right">
          <template #default="{ row }">
            <el-button type="primary" link :disabled="busy" :loading="syncing === row.reference" @click="refresh(row.reference)">
              查询雅本
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="order-list-footer">
        <span>共 {{ total }} 笔</span>
        <el-pagination
          v-if="total > 20" :current-page="page" :page-size="20" :total="total" :disabled="busy"
          layout="prev, pager, next" @current-change="load"
        />
      </div>
    </el-card>
  </section>
  <section v-else class="orders-page">
    <header class="order-detail-heading">
      <h1>订单详情</h1>
      <el-button @click="returnToOrders">返回订单中心</el-button>
    </header>
    <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
    <div v-loading="detailLoading" class="order-detail-content">
      <div v-if="detail" class="order-detail-grid">
        <el-card shadow="never" class="order-list remit-card">
          <template #header><h2>订单摘要</h2></template>
          <el-descriptions class="remit-details" :column="1" size="small" border>
            <el-descriptions-item label="订单号">{{ detail.reference }}</el-descriptions-item>
            <el-descriptions-item label="会员编号">{{ detail.member_id }}</el-descriptions-item>
            <el-descriptions-item label="雅本交易号">{{ detail.trade_id || '—' }}</el-descriptions-item>
            <el-descriptions-item label="当前状态">
              <el-tag :type="statusTone(detail.status_label)" size="small" effect="light">{{ detail.status_label }}</el-tag>
            </el-descriptions-item>
            <el-descriptions-item label="入账 / 下发 / 退款">
              {{ detail.income_code }} / {{ detail.outgoing_code }} / {{ detail.refund_code }}
            </el-descriptions-item>
            <el-descriptions-item label="付款渠道">{{ paymentLabels[detail.payment_method || ''] || '—' }}</el-descriptions-item>
            <el-descriptions-item label="收款渠道">{{ payoutLabels[detail.payout_method || ''] || '—' }}</el-descriptions-item>
            <el-descriptions-item label="汇款本金">{{ amount(detail.principal_minor, currency) }}</el-descriptions-item>
            <el-descriptions-item label="预计到账金额">{{ amount(detail.quote?.payout_minor, yuan) }}</el-descriptions-item>
            <el-descriptions-item label="预计到账时间">{{ detail.quote?.eta || '—' }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
        <el-card shadow="never" class="order-list order-timeline remit-card">
          <template #header><h2>订单时间线（马德里）</h2></template>
          <el-timeline>
            <el-timeline-item :timestamp="dateTime.format(new Date(detail.created_at))">订单创建</el-timeline-item>
            <el-timeline-item v-if="detail.provider_checked_at" :timestamp="dateTime.format(new Date(detail.provider_checked_at))">
              最近一次上游状态查询
            </el-timeline-item>
          </el-timeline>
        </el-card>
        <el-card shadow="never" class="order-list order-fees remit-card">
          <template #header><h2>费用与定价明细</h2></template>
          <el-descriptions class="remit-details" :column="1" size="small" border>
            <el-descriptions-item label="报价汇率">
              {{ typeof detail.quote?.rate_ten_thousandths === 'number' ? (detail.quote.rate_ten_thousandths / 10000).toFixed(4) : '—' }}
            </el-descriptions-item>
            <el-descriptions-item label="手续费">{{ amount(serviceFee(detail.quote), currency) }}</el-descriptions-item>
            <el-descriptions-item label="付款通道费">{{ amount(detail.quote?.channel_fee_minor, currency) }}</el-descriptions-item>
            <el-descriptions-item label="合计支付">{{ amount(detail.quote?.total_payable_minor, currency) }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { get, post } from '@/request/http'
import { useUserStore } from '@/stores/user'
import { getErrorMessage } from '@/utils/error'
import type { Page } from './repository/remitOperationsRepository'

interface Order {
  reference: string
  member_id: string
  principal_minor: number
  status_label: string
  income_code: string
  outgoing_code: string
  refund_code: string
  provider_checked_at: string | null
  created_at: string
  trade_id?: string | null
  payment_method: string | null
  payout_method: string | null
  quote?: {
    rate_ten_thousandths?: number
    payout_minor?: number
    base_fee_minor?: number
    discount_minor?: number
    channel_fee_minor?: number
    total_payable_minor?: number
    eta?: string
  }
}

const path = '/admin/remittance/orders'
const currency = new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' })
const yuan = new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'CNY' })
const dateTime = new Intl.DateTimeFormat('zh-CN', {
  timeZone: 'Europe/Madrid', dateStyle: 'short', timeStyle: 'short', hour12: false
})
const staff = useUserStore()
const route = useRoute()
const router = useRouter()
const statusLabels = [
  ['pending_payment', '待付款'], ['processing', '汇款处理中'], ['paid_out', '已下发'],
  ['refunding', '退款处理中'], ['refunded', '已退款'], ['exception', '异常']
] as const
const summaryLabels = [['total', '全部订单'], ...statusLabels] as const
type Summary = Record<(typeof summaryLabels)[number][0], number>
const paymentLabels: Record<string, string> = { sepa: 'SEPA 银行转账', applePay: 'Apple Pay', card: '银行卡付款' }
const payoutLabels: Record<string, string> = { wechat: '微信收款', bank: '银行卡收款' }
const canSync = computed(() => staff.permissions.includes('remit:orders:sync'))
const page = ref(1)
const total = ref(0)
const summary = ref<Summary>()
const reference = ref('')
const member = ref('')
const status = ref('')
const payment = ref('')
const payout = ref('')
const rows = ref<Order[]>([])
const selected = computed(() => stringQuery('order'))
const detail = ref<Order>()
const detailLoading = ref(false)
const detailError = ref('')
const error = ref('')
const busy = ref(false)
const syncing = ref('')
let listVersion = 0
let detailVersion = 0

function stringQuery(key: string) {
  const value = route.query[key]
  return typeof value === 'string' ? value : ''
}

function amount(minor: number | undefined, formatter: Intl.NumberFormat) {
  return typeof minor === 'number' ? formatter.format(minor / 100) : '—'
}

function serviceFee(quote: Order['quote']) {
  if (typeof quote?.base_fee_minor !== 'number' || typeof quote.discount_minor !== 'number') return undefined
  return quote.base_fee_minor - quote.discount_minor
}

function statusTone(status: string) {
  if (/异常|未完成/.test(status)) return 'danger'
  if (status === '已下发' || status === '已退款') return 'success'
  return 'warning'
}

async function loadList() {
  const version = ++listVersion
  busy.value = true
  error.value = ''
  try {
    const result = await get<Page<Order> & { summary: Summary }>(path, {
      page: stringQuery('page') || 1,
      reference: stringQuery('reference') || undefined, member_id: stringQuery('member_id') || undefined,
      status: stringQuery('status') || undefined, payment_method: stringQuery('payment_method') || undefined,
      payout_method: stringQuery('payout_method') || undefined
    })
    if (version !== listVersion) return
    rows.value = result.list
    total.value = result.total
    page.value = result.page
    summary.value = result.summary
  } catch (reason) {
    if (version !== listVersion) return
    rows.value = []
    total.value = 0
    summary.value = undefined
    error.value = getErrorMessage(reason, '读取订单失败')
  } finally {
    if (version === listVersion) busy.value = false
  }
}

async function loadDetail(order: string) {
  const version = ++detailVersion
  detail.value = undefined
  detailError.value = ''
  if (!/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i.test(order)) {
    detailLoading.value = false
    detailError.value = '订单编号无效'
    return
  }
  detailLoading.value = true
  try {
    const result = await get<Order>(path + '/' + encodeURIComponent(order))
    if (version === detailVersion) detail.value = result
  } catch (reason) {
    if (version === detailVersion) detailError.value = getErrorMessage(reason, '读取订单详情失败')
  } finally {
    if (version === detailVersion) detailLoading.value = false
  }
}

function openDetail(order: string) {
  void router.push({ query: { ...route.query, order }, state: { fromRemitOrderList: true } })
}

function returnToOrders() {
  if (window.history.state?.fromRemitOrderList) {
    router.back()
    return
  }
  void router.replace({ query: { ...route.query, order: undefined } })
}

function load(next: number) {
  void router.replace({ query: { ...route.query, page: next > 1 ? String(next) : undefined } })
}

async function search() {
  if (busy.value) return
  const result = await router.replace({ query: {
    reference: reference.value || undefined, member_id: member.value || undefined,
    status: status.value || undefined, payment_method: payment.value || undefined, payout_method: payout.value || undefined
  } })
  if (result && !selected.value) void loadList()
}

function reset() {
  reference.value = ''
  member.value = ''
  status.value = ''
  payment.value = ''
  payout.value = ''
  void search()
}

async function refresh(order: string) {
  if (busy.value) return
  busy.value = true
  syncing.value = order
  try {
    await post<Order>(path + '/' + order + '/refresh', {})
    if (!selected.value) await loadList()
    ElMessage.success('订单状态已更新')
  } catch (reason) {
    ElMessage.error(getErrorMessage(reason, '查询雅本失败'))
  } finally {
    syncing.value = ''
    busy.value = false
  }
}

watch(() => route.query, () => {
  if (route.path !== '/system/remittance/orders') return
  if (selected.value) {
    void loadDetail(selected.value)
    return
  }
  detailVersion++
  reference.value = stringQuery('reference')
  member.value = stringQuery('member_id')
  status.value = stringQuery('status')
  payment.value = stringQuery('payment_method')
  payout.value = stringQuery('payout_method')
  void loadList()
}, { immediate: true })
</script>

<style scoped>
.orders-page {
  display: grid;
  gap: 16px;
}

.orders-page h1 {
  margin: 0;
}

.order-list h2 {
  font-size: 16px;
  margin: 0;
}

.order-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(118px, 1fr)); gap: 12px; }
.order-summary span { color: var(--omc-color-text-secondary); }
.order-summary strong { display: block; color: var(--omc-color-primary); font-size: 24px; margin-top: 4px; }
.order-detail-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.order-detail-content { min-height: 68px; }
.order-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
.order-detail-grid :deep(.el-descriptions__content) { overflow-wrap: anywhere; }
.order-fees { grid-column: 1 / -1; }
.order-timeline :deep(.el-timeline) { padding-left: 4px; }

.order-reference :deep(span) {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
}

.order-reference {
  max-width: 100%;
}

.order-secondary,
.order-list-footer {
  color: var(--omc-color-text-secondary);
  font-size: 12px;
}

.order-list-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-top: 10px;
}

.order-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.order-filters :deep(.el-form-item) {
  margin: 0;
}

.order-filters .el-button,
.order-filters :deep(.el-input__wrapper),
.order-filters :deep(.el-select__wrapper) {
  box-sizing: border-box;
  height: var(--omc-control-height);
}

.order-filters :deep(.el-form-item__label) { padding-right: 8px; }
.order-filters :deep(.el-input) { width: 144px; }
.member-filter :deep(.el-input) { width: 92px; }
.order-filters :deep(.el-select) { width: 126px; }
.payment-filter :deep(.el-select) { width: 148px; }

@media (max-width: 1150px) {
  .order-filters { gap: 12px; }
  .order-filters :deep(.el-select) { width: 150px; }
}

@media (max-width: 1050px) {
  .order-detail-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>
