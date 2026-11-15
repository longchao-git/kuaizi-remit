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
        <el-card v-for="metric in metrics" :key="metric.label" shadow="never" class="metric-card">
          <div class="metric-head">
            <span>{{ metric.label }}</span>
            <el-icon aria-hidden="true"><component :is="metric.icon" /></el-icon>
          </div>
          <strong class="metric-value">{{ metric.value }}</strong>
        </el-card>
      </div>
      <el-card class="recent-panel" shadow="never">
        <h2>最近汇款</h2>
        <el-table :data="data.recent" stripe empty-text="该区间暂无订单">
          <el-table-column prop="reference" label="订单号" min-width="240" show-overflow-tooltip />
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
    { label: '已确认付款本金（含后续退款）', value: euro(summary.paid_principal_minor), icon: Wallet },
    { label: '已退款', value: summary.refunded_count, icon: RefreshLeft },
  ] : []
})

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
.dashboard-page { padding: 24px; color: var(--omc-color-text); }
.toolbar { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.toolbar h1 { margin: 0; font-size: 24px; }
.toolbar-controls { display: flex; flex-wrap: wrap; gap: 8px; }
.period { margin: 6px 0 0; color: var(--omc-color-text-secondary); font-size: 13px; }
.metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(155px, 1fr)); gap: 12px; }
.metric-card { --el-card-padding: 14px; }
.metric-head { display: flex; min-height: 32px; align-items: flex-start; justify-content: space-between; gap: 8px; color: var(--omc-color-text-secondary); font-size: 13px; }
.metric-head .el-icon { width: 28px; height: 28px; flex: 0 0 28px; border-radius: var(--omc-radius-md); color: var(--omc-color-primary); background: var(--omc-color-primary-soft); }
.metric-value { display: block; margin-top: 6px; color: var(--omc-color-text); font-size: 24px; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
.recent-panel { margin-top: 16px; }
h2 { margin: 0 0 12px; font-size: 17px; }
</style>
