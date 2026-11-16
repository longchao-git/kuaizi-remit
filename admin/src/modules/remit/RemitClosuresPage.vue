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
        <el-table-column label="提交时间" width="160">
          <template #default="{ row }">{{ date(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="审核时间" width="160">
          <template #default="{ row }">{{ date(row.reviewed_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="110">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetails(row)">详情</el-button>
            <el-button v-if="row.status === 'pending'" link type="primary" @click="openReview(row)">审核</el-button>
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
    <el-dialog v-model="detailsOpen" title="注销申请详情" width="min(480px, 92vw)">
      <el-descriptions v-if="selected" :column="1" border>
        <el-descriptions-item label="申请编号">{{ selected.id }}</el-descriptions-item>
        <el-descriptions-item label="会员编号">{{ selected.member_id }}</el-descriptions-item>
        <el-descriptions-item label="状态">{{ statusLabel(selected.status) }}</el-descriptions-item>
        <el-descriptions-item label="注销原因"><span class="closure-note">{{ selected.reason || '—' }}</span></el-descriptions-item>
        <el-descriptions-item label="审核说明"><span class="closure-note">{{ selected.review_note || '—' }}</span></el-descriptions-item>
        <el-descriptions-item label="审核员工">{{ selected.reviewed_by || '—' }}</el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { get, put } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import type { Page } from './repository/remitOperationsRepository'

const labels = { pending: '待审核', approved: '审核通过，待执行', rejected: '审核未通过' }
interface Closure {
  id: string
  member_id: string
  status: keyof typeof labels
  created_at: string
  reviewed_at: string | null
  reason: string | null
  review_note: string | null
  reviewed_by: string
}
const statusLabel = (value: keyof typeof labels) => labels[value]
const date = (value: string | null) => value ? new Date(value).toLocaleString() : '—'
const path = '/admin/remittance/closures'
const rows = ref<Closure[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const error = ref('')
const member = ref('')
const status = ref('')
const reviewOpen = ref(false)
const detailsOpen = ref(false)
const selected = ref<Closure>()
const decision = ref<'approved' | 'rejected'>('approved')
const note = ref('')
const saving = ref(false)
const reviewError = ref('')

async function load(next: number) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const result = await get<Page<Closure>>(path, { page: next, member_id: member.value || undefined, status: status.value || undefined })
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

function openReview(row: Closure) {
  selected.value = row
  decision.value = 'approved'
  note.value = ''
  reviewError.value = ''
  reviewOpen.value = true
}

function openDetails(row: Closure) {
  selected.value = row
  detailsOpen.value = true
}

async function review() {
  if (saving.value || !note.value.trim() || !selected.value) return
  saving.value = true
  reviewError.value = ''
  try {
    await put(path + '/' + selected.value.id, { decision: decision.value, note: note.value.trim() })
    reviewOpen.value = false
    await load(1)
  } catch (failure) {
    reviewError.value = getErrorMessage(failure, '保存审核结果失败')
  } finally {
    saving.value = false
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
