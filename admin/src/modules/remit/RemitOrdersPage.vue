<template>
  <section class="orders-page">
    <h1>订单中心</h1>
    <el-form class="order-filters" inline @submit.prevent="search">
      <el-form-item label="订单编号"><el-input v-model.trim="reference" maxlength="36" clearable /></el-form-item>
      <el-form-item label="会员编号"><el-input v-model.trim="member" maxlength="18" clearable /></el-form-item>
      <el-form-item>
        <el-button native-type="submit" type="primary" :loading="busy">查询</el-button>
        <el-button :disabled="busy" @click="reset">重置</el-button>
      </el-form-item>
    </el-form>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-card shadow="never" class="order-list">
      <template #header><h2>记录列表</h2></template>
      <el-table
        v-loading="busy && !syncing"
        :data="rows"
        :empty-text="error ? '读取失败' : '暂无汇款订单'"
        max-height="max(300px, calc(100vh - 350px))"
        scrollbar-always-on
      >
        <el-table-column label="订单号 / 用户" min-width="240">
          <template #default="{ row }">
            <el-button link type="primary" class="order-reference" @click="selected = row.reference">
              {{ row.reference }}
            </el-button>
            <div class="order-secondary">会员 {{ row.member_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" min-width="155">
          <template #default="{ row }">{{ dateTime.format(new Date(row.created_at)) }}</template>
        </el-table-column>
        <el-table-column label="汇款本金" min-width="115">
          <template #default="{ row }">{{ currency.format(row.principal_minor / 100) }}</template>
        </el-table-column>
        <el-table-column label="预计到账" min-width="115">
          <template #default="{ row }">{{ amount(row.quote?.payout_minor, yuan) }}</template>
        </el-table-column>
        <el-table-column label="手续费" min-width="100">
          <template #default="{ row }">{{ amount(serviceFee(row.quote), currency) }}</template>
        </el-table-column>
        <el-table-column label="状态 / ETA" min-width="165">
          <template #default="{ row }">
            <el-tag :type="statusTone(row.status_label)" effect="light">{{ row.status_label }}</el-tag>
            <div v-if="row.quote?.eta" class="order-secondary">{{ row.quote.eta }}</div>
          </template>
        </el-table-column>
        <el-table-column v-if="canSync" label="操作" width="116" fixed="right">
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
    <el-drawer :model-value="Boolean(detail)" title="订单详情" size="min(540px, 94vw)" @close="selected = ''">
      <el-descriptions v-if="detail" :column="1" border>
        <el-descriptions-item label="订单号">{{ detail.reference }}</el-descriptions-item>
        <el-descriptions-item label="会员编号">{{ detail.member_id }}</el-descriptions-item>
        <el-descriptions-item label="雅本交易号">{{ detail.trade_id || '—' }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ dateTime.format(new Date(detail.created_at)) }}</el-descriptions-item>
        <el-descriptions-item label="汇款本金">{{ amount(detail.principal_minor, currency) }}</el-descriptions-item>
        <el-descriptions-item label="预计到账">{{ amount(detail.quote?.payout_minor, yuan) }}</el-descriptions-item>
        <el-descriptions-item label="手续费">{{ amount(serviceFee(detail.quote), currency) }}</el-descriptions-item>
        <el-descriptions-item label="付款通道费">{{ amount(detail.quote?.channel_fee_minor, currency) }}</el-descriptions-item>
        <el-descriptions-item label="合计支付">{{ amount(detail.quote?.total_payable_minor, currency) }}</el-descriptions-item>
        <el-descriptions-item label="当前状态">{{ detail.status_label }}</el-descriptions-item>
        <el-descriptions-item label="入账 / 下发 / 退款">
          {{ detail.income_code }} / {{ detail.outgoing_code }} / {{ detail.refund_code }}
        </el-descriptions-item>
        <el-descriptions-item label="最近查询">
          {{ detail.provider_checked_at ? dateTime.format(new Date(detail.provider_checked_at)) : '—' }}
        </el-descriptions-item>
      </el-descriptions>
    </el-drawer>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
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
  quote?: {
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
const canSync = computed(() => staff.permissions.includes('remit:orders:sync'))
const page = ref(1)
const total = ref(0)
const reference = ref('')
const member = ref('')
const rows = ref<Order[]>([])
const selected = ref('')
const detail = computed(() => rows.value.find(row => row.reference === selected.value))
const error = ref('')
const busy = ref(false)
const syncing = ref('')
let filters = { reference: '', member_id: '' }

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

async function load(next: number) {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const result = await get<Page<Order>>(path, {
      page: next, reference: filters.reference || undefined, member_id: filters.member_id || undefined
    })
    rows.value = result.list
    total.value = result.total
    page.value = result.page
  } catch (reason) {
    rows.value = []
    total.value = 0
    error.value = getErrorMessage(reason, '读取订单失败')
  } finally {
    busy.value = false
  }
}

function search() {
  if (busy.value) return
  filters = { reference: reference.value, member_id: member.value }
  void load(1)
}

function reset() {
  reference.value = ''
  member.value = ''
  search()
}

async function refresh(order: string) {
  if (busy.value) return
  busy.value = true
  syncing.value = order
  try {
    const result = await post<Order>(path + '/' + order + '/refresh', {})
    rows.value = rows.value.map((row) => row.reference === order ? result : row)
    ElMessage.success('订单状态已更新')
  } catch (reason) {
    ElMessage.error(getErrorMessage(reason, '查询雅本失败'))
  } finally {
    syncing.value = ''
    busy.value = false
  }
}

onMounted(() => { void load(1) })
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
  gap: 12px 20px;
}

.order-filters :deep(.el-form-item) {
  margin: 0;
}

.order-filters .el-button,
.order-filters :deep(.el-input__wrapper) {
  box-sizing: border-box;
  height: var(--omc-control-height);
}

@media (max-width: 1150px) {
  .order-filters { gap: 12px; }
  .order-filters :deep(.el-input) { width: 174px; }
}
</style>
