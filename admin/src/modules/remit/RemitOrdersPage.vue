<template>
  <section class="orders-page">
    <h1>汇款订单</h1>
    <el-form class="order-filters" inline @submit.prevent="search">
      <el-form-item label="订单编号"><el-input v-model.trim="reference" maxlength="36" clearable /></el-form-item>
      <el-form-item label="会员编号"><el-input v-model.trim="member" maxlength="18" clearable /></el-form-item>
      <el-form-item>
        <el-button native-type="submit" type="primary" :loading="busy">查询</el-button>
        <el-button :disabled="busy" @click="reset">重置</el-button>
      </el-form-item>
    </el-form>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table :data="rows" :empty-text="error ? '读取失败' : '暂无汇款订单'" v-loading="busy && !syncing">
      <el-table-column prop="reference" label="订单编号" min-width="260" show-overflow-tooltip />
      <el-table-column prop="member_id" label="会员" width="80" />
      <el-table-column label="汇款本金" min-width="115">
        <template #default="{ row }">{{ currency.format(row.principal_minor / 100) }}</template>
      </el-table-column>
      <el-table-column prop="status_label" label="状态" min-width="145" />
      <el-table-column label="入账 / 下发 / 退款" min-width="155">
        <template #default="{ row }">{{ row.income_code }} / {{ row.outgoing_code }} / {{ row.refund_code }}</template>
      </el-table-column>
      <el-table-column label="最近查询" min-width="170">
        <template #default="{ row }">{{ row.provider_checked_at ? new Date(row.provider_checked_at).toLocaleString() : '—' }}</template>
      </el-table-column>
      <el-table-column v-if="canSync" label="操作" width="116" fixed="right">
        <template #default="{ row }">
          <el-button type="primary" link :disabled="busy" :loading="syncing === row.reference" @click="refresh(row.reference)">
            查询雅本
          </el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="total > 20" :current-page="page" :page-size="20" :total="total" :disabled="busy"
      layout="prev, pager, next" @current-change="load"
    />
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
}

const path = '/admin/remittance/orders'
const currency = new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' })
const staff = useUserStore()
const canSync = computed(() => staff.permissions.includes('remit:orders:sync'))
const page = ref(1)
const total = ref(0)
const reference = ref('')
const member = ref('')
const rows = ref<Order[]>([])
const error = ref('')
const busy = ref(false)
const syncing = ref('')
let filters = { reference: '', member_id: '' }

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

.order-filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 20px;
}

.order-filters :deep(.el-form-item) {
  margin: 0;
}
</style>
