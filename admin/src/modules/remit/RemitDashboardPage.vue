<template>
  <section v-loading="loading" class="dashboard-page">
    <header class="toolbar">
      <h1>经营仪表盘</h1>
      <el-radio-group v-model="days" :disabled="loading" aria-label="统计区间" @change="load">
        <el-radio-button :value="1">今天</el-radio-button>
        <el-radio-button :value="7">近 7 天</el-radio-button>
        <el-radio-button :value="30">近 30 天</el-radio-button>
      </el-radio-group>
      <el-button :disabled="loading" @click="load">刷新</el-button>
    </header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <template v-if="data">
      <p class="period">{{ data.start_date }} 至 {{ data.end_date }} · 西班牙时间 · 按订单创建日统计</p>
      <div class="metrics">
        <el-card v-for="metric in metrics" :key="metric.label" shadow="never">
          <div class="metric-label">{{ metric.label }}</div>
          <strong>{{ metric.value }}</strong>
        </el-card>
      </div>
      <h2>最近汇款</h2>
      <el-table :data="data.recent" border empty-text="该区间暂无订单">
        <el-table-column prop="reference" label="订单号" min-width="240" show-overflow-tooltip />
        <el-table-column label="汇款本金" width="135">
          <template #default="{ row }">{{ euro(row.principal_minor) }}</template>
        </el-table-column>
        <el-table-column prop="status_label" label="当前状态" min-width="150" />
        <el-table-column label="创建时间" width="180">
          <template #default="{ row }">{{ madridTime.format(new Date(row.created_at)) }}</template>
        </el-table-column>
      </el-table>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
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
    { label: '汇款订单', value: summary.order_count },
    { label: '已确认付款', value: summary.paid_count },
    { label: '已确认付款本金（含后续退款）', value: euro(summary.paid_principal_minor) },
    { label: '已下发', value: summary.paid_out_count },
    { label: '已退款', value: summary.refunded_count },
  ] : []
})

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
.dashboard-page { padding: 24px; }
.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 16px; }
.toolbar h1 { margin: 0 auto 0 0; font-size: 24px; }
.period { margin: 0 0 16px; }
.period, .metric-label { color: var(--el-text-color-secondary); }
.metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(155px, 1fr)); gap: 12px; }
.metrics strong { display: block; margin-top: 8px; font-size: 24px; }
h2 { margin: 24px 0 12px; font-size: 18px; }
</style>
