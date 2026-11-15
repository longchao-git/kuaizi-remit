<template>
  <section class="push-page">
    <h1>Push 提醒</h1>
    <el-tabs v-model="tab" @tab-change="refresh">
      <el-tab-pane label="提醒计划" name="plans">
        <div class="toolbar"><el-button type="primary" @click="edit()">新增提醒计划</el-button></div>
        <el-table scrollbar-always-on v-loading="loading" :data="plans" border empty-text="暂无提醒计划">
          <el-table-column prop="name" label="计划名称" min-width="150" show-overflow-tooltip />
          <el-table-column label="目标人群" min-width="175">
            <template #default>完成认证、尚未首汇</template>
          </el-table-column>
          <el-table-column label="发送时间" min-width="180">
            <template #default="{ row }">{{ row.send_time }} · {{ row.timezone }}</template>
          </el-table-column>
          <el-table-column prop="title" label="站内通知标题" min-width="140" show-overflow-tooltip />
          <el-table-column label="状态" width="90">
            <template #default="{ row }">{{ planLabel(row.status) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button v-if="row.status !== 'active'" link type="primary" @click="edit(row)">编辑</el-button>
              <el-button link type="primary" :disabled="changing" @click="changeStatus(row)">
                {{ row.status === 'active' ? '暂停' : '启用' }}
              </el-button>
              <el-button link type="primary" @click="showRecords(row)">发送记录</el-button>
            </template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
      <el-tab-pane label="发送记录" name="records">
        <div class="toolbar">
          <el-button v-if="filter" :disabled="loading" @click="clearFilter">查看全部计划</el-button>
          <el-button :disabled="loading" @click="refresh">刷新</el-button>
          <span v-if="filter">{{ filter.name }}</span>
        </div>
        <el-table scrollbar-always-on v-loading="loading" :data="records" border empty-text="暂无发送记录">
          <el-table-column prop="plan_name" label="计划名称" min-width="130" show-overflow-tooltip />
          <el-table-column prop="member_id" label="会员编号" width="95" />
          <el-table-column prop="notice_id" label="站内消息编号" width="115" />
          <el-table-column prop="title" label="消息标题" min-width="160" show-overflow-tooltip />
          <el-table-column label="系统推送" min-width="210">
            <template #default="{ row }">{{ deliveryLabel(row) }}</template>
          </el-table-column>
          <el-table-column label="站内发送时间" width="180">
            <template #default="{ row }">{{ new Date(row.created_at).toLocaleString() }}</template>
          </el-table-column>
        </el-table>
      </el-tab-pane>
    </el-tabs>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-pagination
      v-if="total > 20" :current-page="page" :page-size="20" :total="total" :disabled="loading"
      layout="prev, pager, next" @current-change="load"
    />
    <el-dialog v-model="editorOpen" :title="selected ? '编辑提醒计划' : '新增提醒计划'" width="min(520px, 92vw)" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent="save">
        <el-form-item label="计划名称" required><el-input v-model="draft.name" maxlength="80" /></el-form-item>
        <el-form-item label="站内通知标题" required><el-input v-model="draft.title" maxlength="80" /></el-form-item>
        <el-form-item label="站内通知内容" required>
          <el-input v-model="draft.body" type="textarea" maxlength="500" :rows="3" show-word-limit />
        </el-form-item>
        <div class="schedule">
          <el-form-item label="计划发送时间" required>
            <el-time-picker v-model="draft.send_time" format="HH:mm" value-format="HH:mm" :clearable="false" />
          </el-form-item>
          <el-form-item label="计划时区（IANA）" required>
            <el-input v-model="draft.timezone" maxlength="64" placeholder="Europe/Madrid" />
          </el-form-item>
        </div>
        <p class="plan-note">启用后按计划时区发送。每个计划只提醒同一会员一次。</p>
        <el-alert v-if="editorError" :title="editorError" type="error" :closable="false" />
        <div class="dialog-actions">
          <el-button :disabled="saving" @click="editorOpen = false">取消</el-button>
          <el-button native-type="submit" type="primary" :loading="saving">保存计划</el-button>
        </div>
      </el-form>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessageBox } from 'element-plus'
import { get, post, put } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import type { Page } from './repository/remitOperationsRepository'

interface Draft {
  name: string
  title: string
  body: string
  send_time: string
  timezone: string
}
const planLabels = { draft: '草稿', active: '已启用', paused: '已暂停' }
interface Plan extends Draft {
  id: string
  status: keyof typeof planLabels
}
const pushLabels = {
  preparing: '等待入队', disabled: '系统推送未开通', no_device: '无可推送设备',
  processed: '已处理', dispatch_failed: '推送准备失败',
}
const deviceLabels = { queued: '待发送', accepted: '网关已受理', skipped: '已跳过', failed: '失败' }
interface RecordRow {
  id: string
  created_at: string
  push_status: keyof typeof pushLabels
  deliveries: Partial<Record<keyof typeof deviceLabels, number>>
}
const path = '/admin/remittance/push'
const planLabel = (status: keyof typeof planLabels) => planLabels[status]
const blank = (): Draft => ({ name: '', title: '', body: '', send_time: '18:30', timezone: 'Europe/Madrid' })

const tab = ref<'plans' | 'records'>('plans')
const plans = ref<Plan[]>([])
const records = ref<RecordRow[]>([])
const filter = ref<Plan>()
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const changing = ref(false)
const error = ref('')
const editorOpen = ref(false)
const editorError = ref('')
const saving = ref(false)
const selected = ref('')
const draft = reactive(blank())
let revision = 0

async function load(next: number) {
  const request = ++revision
  const view = tab.value
  loading.value = true
  error.value = ''
  try {
    const result = view === 'plans'
      ? await get<Page<Plan>>(path + '/plans', { page: next })
      : await get<Page<RecordRow>>(path + '/records', { page: next, plan_id: filter.value?.id })
    if (request !== revision) return
    if (view === 'plans') plans.value = result.list as Plan[]
    else records.value = result.list as RecordRow[]
    page.value = result.page
    total.value = result.total
  } catch (failure) {
    if (request === revision) error.value = getErrorMessage(failure, '加载提醒数据失败')
  } finally {
    if (request === revision) loading.value = false
  }
}

function refresh() {
  return load(1)
}

function clearFilter() {
  filter.value = undefined
  void refresh()
}

function showRecords(plan: Plan) {
  filter.value = plan
  tab.value = 'records'
  records.value = []
  void refresh()
}

function deliveryLabel(row: RecordRow) {
  const queuedExpired = Date.now() - new Date(row.created_at).getTime() >= 60 * 60 * 1000
  const states = (Object.keys(deviceLabels) as (keyof typeof deviceLabels)[])
    .filter(status => row.deliveries[status])
    .map(status => (status === 'queued' && queuedExpired ? '超时未确认' : deviceLabels[status]) + ' ' + row.deliveries[status])
  if (row.push_status === 'preparing' && queuedExpired) return '入队超时未确认'
  if (row.push_status === 'dispatch_failed') states.push(pushLabels.dispatch_failed)
  return states.join(' · ') || pushLabels[row.push_status]
}

function edit(plan?: Plan) {
  selected.value = plan?.id ?? ''
  const fields = plan ?? blank()
  Object.assign(draft, {
    name: fields.name, title: fields.title, body: fields.body, send_time: fields.send_time, timezone: fields.timezone,
  })
  editorError.value = ''
  editorOpen.value = true
}

async function save() {
  if (saving.value) return
  const input = { ...draft, name: draft.name.trim(), title: draft.title.trim(), body: draft.body.trim(), timezone: draft.timezone.trim() }
  if (!input.name || !input.title || !input.body || !input.send_time || !input.timezone) {
    editorError.value = '请填写计划名称、站内消息、发送时间和时区'
    return
  }
  saving.value = true
  editorError.value = ''
  try {
    if (selected.value) await put(path + '/plans/' + selected.value, input)
    else await post(path + '/plans', input)
    editorOpen.value = false
    await refresh()
  } catch (failure) {
    editorError.value = getErrorMessage(failure, '保存提醒计划失败')
  } finally {
    saving.value = false
  }
}

async function changeStatus(plan: Plan) {
  if (changing.value) return
  const active = plan.status !== 'active'
  changing.value = true
  error.value = ''
  try {
    if (active) {
      const message = '启用后将在 ' + plan.send_time + '（' + plan.timezone + '）向符合条件的会员发送提醒。'
      const confirmed = await ElMessageBox.confirm(message, '启用提醒计划', {
        confirmButtonText: '启用', cancelButtonText: '取消',
      }).then(() => true, () => false)
      if (!confirmed) return
    }
    await put(path + '/plans/' + plan.id + '/status', { active })
    await refresh()
  } catch (failure) {
    error.value = getErrorMessage(failure, '更新计划状态失败')
  } finally {
    changing.value = false
  }
}

onMounted(refresh)
</script>

<style scoped>
.push-page { padding: 24px; }
h1 { margin: 0 0 20px; font-size: 24px; }
.toolbar { display: flex; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
.toolbar :deep(.el-button + .el-button), .dialog-actions :deep(.el-button + .el-button) { margin-left: 0; }
.schedule { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.schedule :deep(.el-date-editor) { width: 100%; }
.plan-note { color: var(--el-text-color-secondary); margin: 0 0 12px; }
.dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; }
.el-pagination { margin-top: 16px; }
</style>
