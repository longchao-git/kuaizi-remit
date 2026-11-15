<template>
  <section class="materials-page">
    <h1>补件管理</h1>
    <div><el-button type="primary" @click="openForm">登记雅本补件</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table scrollbar-always-on v-loading="loading" :data="rows" :empty-text="error ? '读取失败' : '暂无补件任务'">
      <el-table-column prop="member_id" label="会员编号" width="100" />
      <el-table-column prop="question_id" label="雅本问题编号" min-width="170" show-overflow-tooltip />
      <el-table-column prop="order_reference" label="订单编号" min-width="180" show-overflow-tooltip />
      <el-table-column prop="instruction" label="补件要求" min-width="150" show-overflow-tooltip />
      <el-table-column label="截止时间" min-width="165">
        <template #default="{ row }">{{ new Date(row.deadline_at).toLocaleString() }}</template>
      </el-table-column>
      <el-table-column label="状态" width="98">
        <template #default="{ row }">{{ labels[row.status as keyof typeof labels] }}</template>
      </el-table-column>
    </el-table>
    <el-pagination v-if="total > 20" :current-page="page" :page-size="20" :total="total" :disabled="loading"
      layout="prev, pager, next" @current-change="load"
    />
    <el-dialog v-model="dialogOpen" title="登记雅本补件" width="min(560px, 94vw)" :closable="!saving" :close-on-click-modal="!saving">
      <el-form label-position="top" @submit.prevent="submit">
        <el-form-item label="会员编号"><el-input v-model.trim="form.memberId" maxlength="18" :disabled="saving" /></el-form-item>
        <el-form-item label="订单编号（选填）"><el-input v-model.trim="form.order" maxlength="36" :disabled="saving" /></el-form-item>
        <el-form-item label="雅本问题编号"><el-input v-model.trim="form.question" maxlength="200" :disabled="saving" /></el-form-item>
        <el-form-item label="补件要求"><el-input v-model.trim="form.instruction" type="textarea" maxlength="500" :disabled="saving" /></el-form-item>
        <el-form-item label="截止时间（本地时区）">
          <el-date-picker v-model="form.deadline" type="datetime" :disabled="saving" placeholder="选择截止时间" />
        </el-form-item>
        <el-alert v-if="formError" :title="formError" type="error" :closable="false" />
        <el-button native-type="submit" type="primary" :loading="saving">保存</el-button>
      </el-form>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { get, post } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import type { Page } from './repository/remitOperationsRepository'

const labels = { '6001': '待提交', '6002': '已完成', '6003': '已过期' }
interface Task {
  member_id: string
  order_reference: string | null
  question_id: string
  instruction: string
  deadline_at: string
  status: keyof typeof labels
}

const path = '/admin/remittance/materials'
const rows = ref<Task[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const formError = ref('')
const dialogOpen = ref(false)
const form = reactive({ memberId: '', order: '', question: '', instruction: '', deadline: null as Date | null })

function openForm() {
  Object.assign(form, { memberId: '', order: '', question: '', instruction: '', deadline: null })
  formError.value = ''
  dialogOpen.value = true
}

async function load(next: number) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const data = await get<Page<Task>>(path, { page: next })
    rows.value = data.list
    total.value = data.total
    page.value = data.page
  } catch (reason) {
    error.value = getErrorMessage(reason, '读取补件任务失败')
  } finally {
    loading.value = false
  }
}

async function submit() {
  if (saving.value) return
  formError.value = ''
  if (!/^[1-9]\d{0,17}$/.test(form.memberId) || !form.question || !form.instruction || !form.deadline) {
    formError.value = '请填写会员编号、问题编号、补件要求和截止时间'
    return
  }

  saving.value = true
  try {
    await post<Task>(path, {
      member_id: form.memberId, order_reference: form.order || null, question_id: form.question,
      instruction: form.instruction, deadline_at: Math.floor(form.deadline.getTime() / 1000)
    })
    dialogOpen.value = false
    await load(1)
  } catch (reason) {
    formError.value = getErrorMessage(reason, '保存补件任务失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => { void load(1) })
</script>

<style scoped>
.materials-page { display: grid; gap: 16px; }
.materials-page h1 { margin: 0; }
.el-alert + .el-button { margin-top: 12px; }
</style>
