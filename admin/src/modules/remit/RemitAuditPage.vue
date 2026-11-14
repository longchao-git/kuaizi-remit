<template>
  <section class="audit-page">
    <h1>操作审计</h1>
    <el-select v-model="action" aria-label="操作类型" @change="load(1)">
      <el-option label="全部操作" value="" />
      <el-option v-for="(label, value) in auditActions" :key="value" :label="label" :value="value" />
    </el-select>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="records" :empty-text="error ? '读取失败' : '暂无操作记录'">
      <el-table-column label="操作" min-width="140">
        <template #default="{ row }">{{ auditActions[row.action as AuditAction] }}</template>
      </el-table-column>
      <el-table-column label="操作人" min-width="150">
        <template #default="{ row }">{{ row.operator_name ?? '—' }}<span v-if="row.operator_id"> · {{ row.operator_id }}</span></template>
      </el-table-column>
      <el-table-column prop="reference_id" label="记录编号" min-width="120" />
      <el-table-column label="时间" min-width="180">
        <template #default="{ row }">{{ new Date(row.occurred_at).toLocaleString() }}</template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="total > 20" :current-page="page" :disabled="loading" :page-size="20" :total="total"
      layout="prev, pager, next" @current-change="load"
    />
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { get } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import type { Page } from './repository/remitOperationsRepository'

const auditActions = {
  rules_saved: '保存汇款规则',
  role_changed: '变更员工角色',
  coupon_issued: '发放优惠券',
  coupon_revoked: '撤销优惠券',
  notice_sent: '发送站内通知',
  reconciliation_imported: '导入对账批次',
  reconciliation_reviewed: '复核对账差异',
}
type AuditAction = keyof typeof auditActions
interface AuditRecord {
  action: AuditAction
  reference_id: string
  operator_id: string | null
  operator_name: string | null
  occurred_at: string
}
const action = ref<AuditAction | ''>('')
const records = ref<AuditRecord[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const error = ref('')
let version = 0

async function load(next: number) {
  const current = ++version
  loading.value = true
  error.value = ''
  try {
    const result = await get<Page<AuditRecord>>('/admin/remittance/audit', { page: next, action: action.value || undefined })
    if (current !== version) return
    records.value = result.list
    total.value = result.total
    page.value = result.page
  } catch (reason) {
    if (current !== version) return
    records.value = []
    total.value = 0
    error.value = getErrorMessage(reason, '读取操作审计失败')
  } finally {
    if (current === version) loading.value = false
  }
}

onMounted(() => void load(1))
</script>

<style scoped>
.audit-page { display: grid; gap: 16px; padding: 24px 28px; }
.audit-page h1 { margin: 0; }
.audit-page .el-select { width: min(220px, 100%); }
</style>
