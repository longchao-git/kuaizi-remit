<template>
  <section v-loading="loading" class="dashboard-page">
    <header class="toolbar">
      <div>
        <h1>经营仪表盘</h1>
        <p v-if="data" class="period">{{ data.start_date }} 至 {{ data.end_date }} · 西班牙时间 · 按订单创建日统计</p>
      </div>
      <div class="toolbar-controls">
        <el-radio-group v-model="days" :disabled="loading" aria-label="统计区间" @change="load">
          <el-radio-button :value="1">今天</el-radio-button>
          <el-radio-button :value="7">近 7 天</el-radio-button>
          <el-radio-button :value="30">近 30 天</el-radio-button>
        </el-radio-group>
        <el-button :disabled="loading" @click="load">刷新</el-button>
      </div>
    </header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <template v-if="data">
      <div class="metrics">
        <el-card v-for="metric in metrics" :key="metric.label" shadow="never" class="metric-card"
          :class="{ 'metric-card--amount': metric.isAmount }">
          <div class="metric-head">
            <span>{{ metric.label }}</span>
            <el-icon aria-hidden="true"><component :is="metric.icon" /></el-icon>
          </div>
          <strong class="metric-value">{{ metric.value }}</strong>
        </el-card>
      </div>
      <div class="dashboard-panels">
        <el-card shadow="never">
          <h2>订单与出金趋势</h2>
          <div v-if="data.metrics.order_count > 0" class="trend-chart">
            <div class="trend-legend">
              <span>下单量</span>
              <span>已下发</span>
            </div>
            <ul class="trend-days" aria-label="每日下单量和已下发笔数">
              <li v-for="(day, index) in data.trend" :key="day.date"
                :aria-label="`${day.date}，下单 ${day.order_count} 笔，已下发 ${day.paid_out_count} 笔`"
                :title="`${day.date} · 下单 ${day.order_count} 笔 · 已下发 ${day.paid_out_count} 笔`">
                <div class="trend-bars" aria-hidden="true">
                  <span :style="{ height: `${day.order_count / trendMax * 100}%` }"></span>
                  <span :style="{ height: `${day.paid_out_count / trendMax * 100}%` }"></span>
                </div>
                <span v-if="data.trend.length <= 7 || index % 5 === 0" class="trend-date" aria-hidden="true">{{ day.date.slice(5) }}</span>
              </li>
            </ul>
          </div>
          <el-empty v-else description="该区间暂无订单" :image-size="64" />
        </el-card>
        <el-card shadow="never">
          <h2>风险与异常</h2>
          <dl class="exception-list">
            <div v-for="exception in exceptions" :key="exception.label">
              <dt>{{ exception.label }}</dt>
              <dd><el-tag :type="exception.count ? 'danger' : 'info'" effect="plain">{{ exception.count }} 笔</el-tag></dd>
            </div>
          </dl>
        </el-card>
      </div>
      <el-card class="recent-panel" shadow="never">
        <h2>最近汇款</h2>
        <el-table :data="data.recent" stripe empty-text="该区间暂无订单">
          <el-table-column label="订单号" min-width="250" show-overflow-tooltip>
            <template #default="{ row }">
              <router-link class="recent-link" :to="{ path: '/system/remittance/orders', query: { order: row.reference } }">
                {{ row.reference }}
              </router-link>
            </template>
          </el-table-column>
          <el-table-column label="汇款本金" width="135">
            <template #default="{ row }">{{ euro(row.principal_minor) }}</template>
          </el-table-column>
          <el-table-column label="当前状态" min-width="150">
            <template #default="{ row }">
              <el-tag :type="statusTag(row.status_label)" size="small" round>{{ row.status_label }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="创建时间" width="180">
            <template #default="{ row }">{{ madridTime.format(new Date(row.created_at)) }}</template>
          </el-table-column>
        </el-table>
      </el-card>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { CircleCheck, Document, Promotion, RefreshLeft, Wallet } from '@element-plus/icons-vue'
import { get } from '@/request/http'
import { getErrorMessage } from '@/utils/error'

interface Dashboard {
  start_date: string
  end_date: string
  metrics: {
    order_count: number
    paid_count: number
    paid_principal_minor: number
    paid_out_count: number
    refunded_count: number
  }
  trend: { date: string; order_count: number; paid_out_count: number }[]
  exceptions: {
    payment_failed_count: number
    payout_failed_count: number
    refund_failed_count: number
  }
  recent: { reference: string; principal_minor: number; status_label: string; created_at: string }[]
}

const days = ref(7)
const loading = ref(false)
const error = ref('')
const data = ref<Dashboard>()
const currency = new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'EUR' })
const madridTime = new Intl.DateTimeFormat('zh-CN', { timeZone: 'Europe/Madrid', dateStyle: 'short', timeStyle: 'short' })
const euro = (minor: number) => currency.format(minor / 100)
const metrics = computed(() => {
  const summary = data.value?.metrics
  return summary ? [
    { label: '汇款订单', value: summary.order_count, icon: Document },
    { label: '已确认付款', value: summary.paid_count, icon: CircleCheck },
    { label: '已下发', value: summary.paid_out_count, icon: Promotion },
    { label: '已付本金（含退款）', value: euro(summary.paid_principal_minor), icon: Wallet, isAmount: true },
    { label: '已退款', value: summary.refunded_count, icon: RefreshLeft },
  ] : []
})
const exceptions = computed(() => {
  const counts = data.value?.exceptions
  return counts ? [
    { label: '付款未完成', count: counts.payment_failed_count },
    { label: '下发异常', count: counts.payout_failed_count },
    { label: '退款异常', count: counts.refund_failed_count },
  ] : []
})
const trendMax = computed(() => Math.max(1, ...(data.value?.trend.map(day => day.order_count) ?? [])))

function statusTag(label: string) {
  if (label === '已下发') return 'success'
  if (/退款|异常|未完成/.test(label)) return 'warning'
  return 'info'
}

async function load() {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    data.value = await get<Dashboard>('/admin/remittance/dashboard', { days: days.value })
  } catch (failure) {
    data.value = undefined
    error.value = getErrorMessage(failure, '无法加载经营数据')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.dashboard-page { color: var(--omc-color-text); }
.toolbar { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.toolbar h1 { margin: 0; font-size: 24px; }
.toolbar-controls { display: flex; flex-wrap: wrap; gap: 8px; }
.recent-link { color: var(--omc-color-primary); }
.trend-chart {
  --trend-orders: #c5d7e3;
  --trend-paid-out: #298f83;
}
.trend-legend { display: flex; justify-content: flex-end; gap: 16px; }
.trend-legend span { display: flex; align-items: center; gap: 5px; color: var(--omc-color-text-secondary); font-size: 12px; }
.trend-legend span::before { content: ''; width: 9px; height: 9px; border-radius: 3px; background: var(--trend-orders); }
.trend-legend span + span::before { background: var(--trend-paid-out); }
.trend-days {
  display: flex;
  list-style: none;
  margin: 12px 0 27px;
  padding: 0;
  height: 160px;
  border-bottom: 1px solid #e5eaf0;
  background: repeating-linear-gradient(to bottom, #e5eaf0 0, #e5eaf0 1px, transparent 1px, transparent 25%);
}
.trend-days li { position: relative; flex: 1; min-width: 0; }
.trend-bars { height: 100%; display: flex; justify-content: center; align-items: flex-end; gap: min(4px, 12%); }
.trend-bars span { width: 31%; max-width: 24px; border-radius: 3px 3px 0 0; background: var(--trend-orders); }
.trend-bars span + span { background: var(--trend-paid-out); }
.trend-date { position: absolute; top: calc(100% + 6px); left: 50%; transform: translateX(-50%); color: var(--omc-color-text-secondary); font-size: 11px; white-space: nowrap; }
.period { margin: 6px 0 0; color: var(--omc-color-text-secondary); font-size: 13px; }
.metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(0, 1.35fr) minmax(0, 1fr); gap: 12px; }
.metric-card { --el-card-padding: 14px; }
.metric-head { display: flex; min-height: 32px; align-items: flex-start; justify-content: space-between; gap: 8px; color: var(--omc-color-text-secondary); font-size: 13px; }
.metric-head .el-icon { width: 28px; height: 28px; flex: 0 0 28px; border-radius: var(--omc-radius-md); color: var(--omc-color-primary); background: var(--omc-color-primary-soft); }
.metric-value { display: block; margin-top: 6px; color: var(--omc-color-text); font-size: 24px; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.metric-card--amount .metric-value { font-size: clamp(16px, 1.85vw, 24px); }
.dashboard-panels { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 16px; margin-top: 16px; }
.exception-list > div { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 46px; border-bottom: 1px solid var(--omc-color-border-light); }
.exception-list > div:last-child { border-bottom: 0; }
.exception-list dt { color: var(--omc-color-text-secondary); }
@media (max-width: 1000px) {
  .metrics { grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); }
  .dashboard-panels { grid-template-columns: minmax(0, 1fr); }
}
.recent-panel { margin-top: 16px; }
h2 { margin: 0 0 12px; font-size: 17px; }
</style>
