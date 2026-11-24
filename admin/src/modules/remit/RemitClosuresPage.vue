<template>
  <section class="closures-page">
    <h1>注销申请</h1>
    <el-form inline class="filters" @submit.prevent="load(1)">
      <el-form-item label="会员编号">
        <el-input v-model.trim="member" maxlength="18" clearable />
      </el-form-item>
      <el-form-item label="状态">
        <el-select v-model="status" clearable placeholder="全部状态" style="width: 185px">
          <el-option v-for="(label, value) in labels" :key="value" :value="value" :label="label" />
        </el-select>
      </el-form-item>
      <el-button native-type="submit" type="primary" :loading="loading">查询</el-button>
      <el-button :disabled="loading" @click="reset">重置</el-button>
    </el-form>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-card class="remit-card" shadow="never">
      <template #header><h2>申请记录</h2></template>
      <el-table scrollbar-always-on v-loading="loading" :data="rows" border empty-text="暂无注销申请">
        <el-table-column prop="id" label="申请编号" width="92" />
        <el-table-column prop="member_id" label="会员编号" width="92" />
        <el-table-column label="状态" min-width="140">
          <template #default="{ row }">{{ statusLabel(row.status) }}</template>
        </el-table-column>
        <el-table-column label="执行状态" min-width="120">
          <template #default="{ row }">{{ executionLabel(row) }}</template>
        </el-table-column>
        <el-table-column label="提交时间" width="160">
          <template #default="{ row }">{{ date(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="审核时间" width="160">
          <template #default="{ row }">{{ date(row.reviewed_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="160" fixed="right" align="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetails(row)">详情</el-button>
            <el-button v-if="row.status === 'pending'" link type="primary" @click="openReview(row)">审核</el-button>
            <el-button v-if="row.status === 'approved' && row.execution_status !== 'completed'" link type="primary" @click="openExecution(row)">
              {{ row.execution_status === 'not_started' ? '执行注销' : '核实结果' }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="total > 20" :current-page="page" :page-size="20" :total="total" :disabled="loading"
        layout="prev, pager, next" @current-change="load"
      />
    </el-card>
    <el-dialog v-model="reviewOpen" title="审核注销申请" width="min(480px, 92vw)" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent="review">
        <el-form-item label="注销原因"><span class="closure-note">{{ selected?.reason || '—' }}</span></el-form-item>
        <el-form-item label="审核结果">
          <el-radio-group v-model="decision" :disabled="saving">
            <el-radio value="approved">审核通过，待执行</el-radio>
            <el-radio value="rejected">不通过</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="给用户的审核说明" required>
          <el-input v-model="note" type="textarea" :rows="3" maxlength="300" show-word-limit :disabled="saving" />
        </el-form-item>
        <el-alert v-if="reviewError" :title="reviewError" type="error" :closable="false" />
        <div class="dialog-actions">
          <el-button :disabled="saving" @click="reviewOpen = false">取消</el-button>
          <el-button native-type="submit" type="primary" :loading="saving" :disabled="!note.trim()">保存审核结果</el-button>
        </div>
      </el-form>
    </el-dialog>
    <el-dialog
      v-model="executionOpen" :title="isVerification ? '核实注销结果' : '执行账号注销'" width="min(480px, 92vw)"
      :close-on-click-modal="false" :close-on-press-escape="!executing" :show-close="!executing"
    >
      <template v-if="selected">
        <el-descriptions :column="1" border>
          <el-descriptions-item label="会员编号">{{ selected.member_id }}</el-descriptions-item>
          <el-descriptions-item label="执行状态">{{ executionLabel(selected) }}</el-descriptions-item>
          <el-descriptions-item label="处理说明"><span class="closure-note">{{ selected.execution_note || selected.review_note || '—' }}</span></el-descriptions-item>
        </el-descriptions>
        <p v-if="isVerification">查询确认雅本账号已停用后，将完成本地注销并关闭登录。</p>
        <p v-else>执行后将关闭会员登录，并注销已关联的雅本账号。历史订单和财务记录保留。</p>
        <el-checkbox v-if="!isVerification" v-model="executionConfirmed" :disabled="executing">已核对会员申请及未结清业务，确认执行注销</el-checkbox>
        <el-alert v-if="executionError" :title="executionError" type="error" :closable="false" />
      </template>
      <template #footer>
        <el-button :disabled="executing" @click="executionOpen = false">取消</el-button>
        <el-button type="primary" :loading="executing" :disabled="!isVerification && !executionConfirmed" @click="performExecution">
          {{ isVerification ? '核实结果' : '确认执行注销' }}
        </el-button>
      </template>
    </el-dialog>
    <el-dialog v-model="detailsOpen" title="注销申请详情" width="min(480px, 92vw)">
      <el-descriptions v-if="selected" :column="1" border>
        <el-descriptions-item label="申请编号">{{ selected.id }}</el-descriptions-item>
        <el-descriptions-item label="会员编号">{{ selected.member_id }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ statusLabel(selected.status) }}</el-descriptions-item>
        <el-descriptions-item label="注销原因"><span class="closure-note">{{ selected.reason || '—' }}</span></el-descriptions-item>
        <el-descriptions-item label="审核说明"><span class="closure-note">{{ selected.review_note || '—' }}</span></el-descriptions-item>
        <el-descriptions-item label="审核员工">{{ selected.reviewed_by || '—' }}</el-descriptions-item>
        <el-descriptions-item label="执行状态">{{ executionLabel(selected) }}</el-descriptions-item>
        <el-descriptions-item label="处理说明"><span class="closure-note">{{ selected.execution_note || '—' }}</span></el-descriptions-item>
        <el-descriptions-item label="执行员工">{{ selected.executed_by || '—' }}</el-descriptions-item>
        <el-descriptions-item label="完成时间">{{ date(selected.executed_at) }}</el-descriptions-item>
        <el-descriptions-item label="最近核实">{{ date(selected.last_checked_at) }}</el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { getErrorMessage } from '@/utils/error'
import { executeClosure, loadClosureRequests, reviewClosure, verifyClosure } from './repository/remitClosureRepository'
import type { ClosureRecord } from './repository/remitClosureRepository'

const labels = { pending: '待审核', approved: '审核通过', rejected: '审核未通过' }
const executionLabels = { not_started: '待执行', processing: '处理中', pending_confirmation: '待核实', completed: '已注销' }
const statusLabel = (value: keyof typeof labels) => labels[value]
const executionLabel = (row: ClosureRecord) => row.status === 'approved' ? (executionLabels[row.execution_status] || '待核实') : '—'
const date = (value: string | null) => value ? new Date(value).toLocaleString() : '—'
const rows = ref<ClosureRecord[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const error = ref('')
const member = ref('')
const status = ref('')
const reviewOpen = ref(false)
const detailsOpen = ref(false)
const selected = ref<ClosureRecord>()
const decision = ref<'approved' | 'rejected'>('approved')
const note = ref('')
const saving = ref(false)
const reviewError = ref('')
const executionOpen = ref(false)
const executionConfirmed = ref(false)
const executing = ref(false)
const executionError = ref('')
const isVerification = computed(() => selected.value?.execution_status !== 'not_started')

async function load(next: number) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const result = await loadClosureRequests(next, member.value, status.value)
    rows.value = result.list
    total.value = result.total
    page.value = result.page
  } catch (failure) {
    error.value = getErrorMessage(failure, '无法加载注销申请')
  } finally {
    loading.value = false
  }
}

function reset() {
  member.value = ''
  status.value = ''
  void load(1)
}

function openReview(row: ClosureRecord) {
  selected.value = row
  decision.value = 'approved'
  note.value = ''
  reviewError.value = ''
  reviewOpen.value = true
}

function openDetails(row: ClosureRecord) {
  selected.value = row
  detailsOpen.value = true
}

async function review() {
  if (saving.value || !note.value.trim() || !selected.value) return
  saving.value = true
  reviewError.value = ''
  try {
    await reviewClosure(selected.value.id, decision.value, note.value.trim())
    reviewOpen.value = false
    await load(1)
  } catch (failure) {
    reviewError.value = getErrorMessage(failure, '保存审核结果失败')
  } finally {
    saving.value = false
  }
}

function openExecution(row: ClosureRecord) {
  selected.value = row
  executionConfirmed.value = false
  executionError.value = ''
  executionOpen.value = true
}

async function performExecution() {
  if (executing.value || !selected.value || (!isVerification.value && !executionConfirmed.value)) return
  executing.value = true
  executionError.value = ''
  const id = selected.value.id
  try {
    const result = isVerification.value ? await verifyClosure(id) : await executeClosure(id)
    selected.value = result
    executionOpen.value = false
    await load(page.value)
  } catch (failure) {
    executionError.value = getErrorMessage(failure, '无法确认注销结果，请稍后核实')
    await load(page.value)
    selected.value = rows.value.find(row => row.id === id) || selected.value
  } finally {
    executing.value = false
  }
}

onMounted(() => { void load(1) })
</script>

<style scoped>
.closures-page { display: grid; gap: 16px; }
h1 { margin: 0; }
.filters { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 12px; margin-bottom: 16px; }
.filters :deep(.el-form-item), .filters :deep(.el-button + .el-button) { margin: 0; }
.dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.closure-note { overflow-wrap: anywhere; white-space: pre-wrap; }
.el-pagination { margin-top: 16px; }
</style>
