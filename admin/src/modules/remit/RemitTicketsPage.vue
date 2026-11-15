<template>
  <section class="ticket-page">
    <h1>客服工单</h1>
    <div class="toolbar">
      <el-select v-model="status" aria-label="处理状态" @change="load(1)">
        <el-option value="" label="全部状态" />
        <el-option v-for="(label, value) in labels" :key="value" :label="label" :value="value" />
      </el-select>
      <el-button :loading="loading" @click="load(page)">刷新</el-button>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table scrollbar-always-on v-loading="loading" :data="rows" :empty-text="error ? '读取失败' : '暂无客服工单'">
      <el-table-column prop="id" label="工单编号" width="100" />
      <el-table-column prop="member_id" label="会员编号" width="100" />
      <el-table-column prop="subject" label="标题" min-width="160" show-overflow-tooltip />
      <el-table-column label="状态" width="110">
        <template #default="{ row }">{{ labels[row.status as TicketStatus] }}</template>
      </el-table-column>
      <el-table-column label="提交时间" min-width="150">
        <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
      </el-table-column>
      <el-table-column label="操作" width="100">
        <template #default="{ row }"><el-button link type="primary" @click="open(row.id)">查看工单</el-button></template>
      </el-table-column>
    </el-table>
    <el-pagination v-if="total > 20" :current-page="page" :page-size="20" :total="total" layout="prev, pager, next" @current-change="load" />
    <el-dialog v-model="dialog" title="工单详情" width="min(600px, 94vw)" :before-close="close">
      <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
      <section v-loading="reading" class="ticket-detail">
        <template v-if="detail">
          <h2>{{ detail.subject }}</h2>
          <p v-if="detail.order_reference">关联订单 {{ detail.order_reference }}</p>
          <p>工单 #{{ detail.id }} · 会员 #{{ detail.member_id }} · {{ labels[detail.status] }}</p>
          <p class="message">{{ detail.body }}</p>
          <div v-for="event in detail.updates" :key="event.version" class="update">
            <strong>{{ labels[event.status] }} · {{ formatDate(event.created_at) }}</strong>
            <p class="message">{{ event.reply }}</p>
          </div>
          <el-form v-if="detail.status !== 'resolved'" label-position="top" @submit.prevent="submit">
            <el-form-item label="处理状态">
              <el-radio-group v-model="nextStatus" :disabled="saving">
                <el-radio value="processing">处理中</el-radio>
                <el-radio value="resolved">已解决</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="客服回复">
              <el-input v-model="reply" type="textarea" maxlength="500" rows="3" :disabled="saving" />
            </el-form-item>
            <el-button native-type="submit" type="primary" :loading="saving" :disabled="reading || !reply.trim()">提交回复</el-button>
            <el-button :disabled="saving || reading" @click="readDetail(detail.id)">刷新详情</el-button>
          </el-form>
        </template>
      </section>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { get, HttpError, put } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import type { Page } from './repository/remitOperationsRepository'

type TicketStatus = 'open' | 'processing' | 'resolved'
type NextStatus = Exclude<TicketStatus, 'open'>
interface Ticket {
  id: string
  member_id: string
  subject: string
  order_reference: string | null
  status: TicketStatus
  version: number
  created_at: string
}
interface TicketDetail extends Ticket {
  body: string
  updates: { version: number; reply: string; status: NextStatus; created_at: string }[]
}

const labels = { open: '待处理', processing: '处理中', resolved: '已解决' }
const path = '/admin/support/tickets'
const status = ref<TicketStatus | ''>('')
const rows = ref<Ticket[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const error = ref('')
const detail = ref<TicketDetail>()
const detailError = ref('')
const dialog = ref(false)
const reading = ref(false)
const saving = ref(false)
const reply = ref('')
const nextStatus = ref<NextStatus>('processing')
let pending: { key: string; id: string } | undefined
let listRequest = 0
let detailRequest = 0

function formatDate(value: string) {
  return new Date(value).toLocaleString()
}

async function load(next: number) {
  const current = ++listRequest
  loading.value = true
  error.value = ''
  try {
    const result = await get<Page<Ticket>>(path, { page: next, status: status.value || undefined })
    if (current !== listRequest) return
    rows.value = result.list
    page.value = result.page
    total.value = result.total
  } catch (reason) {
    if (current !== listRequest) return
    rows.value = []
    total.value = 0
    error.value = getErrorMessage(reason, '读取工单失败')
  } finally {
    if (current === listRequest) loading.value = false
  }
}

async function readDetail(id: string) {
  const current = ++detailRequest
  reading.value = true
  detailError.value = ''
  try {
    const result = await get<TicketDetail>(`${path}/${id}`)
    if (current === detailRequest) detail.value = result
  } catch (reason) {
    if (current === detailRequest) detailError.value = getErrorMessage(reason, '读取工单详情失败')
  } finally {
    if (current === detailRequest) reading.value = false
  }
}

function open(id: string) {
  detail.value = undefined
  reply.value = ''
  nextStatus.value = 'processing'
  pending = undefined
  dialog.value = true
  void readDetail(id)
}

function close(done: () => void) {
  if (!saving.value) done()
}

async function submit() {
  const ticket = detail.value
  if (!ticket || saving.value || reading.value || !reply.value.trim()) return
  const input = { version: ticket.version, status: nextStatus.value, reply: reply.value.trim() }
  const key = JSON.stringify([ticket.id, input])
  saving.value = true
  detailError.value = ''
  try {
    if (pending?.key !== key) pending = { key, id: crypto.randomUUID() }
    detail.value = await put<TicketDetail>(`${path}/${ticket.id}`, { ...input, client_id: pending.id })
    pending = undefined
    reply.value = ''
    ElMessage.success('已提交回复')
    void load(page.value)
  } catch (reason) {
    const message = getErrorMessage(reason, '回复提交失败')
    if (reason instanceof HttpError && reason.status === 409) await readDetail(ticket.id)
    detailError.value = message
  } finally {
    saving.value = false
  }
}

onMounted(() => void load(1))
</script>

<style scoped>
.ticket-page { display: grid; gap: 16px; padding: 24px 28px; }
.ticket-page h1 { margin: 0; }
.toolbar { display: flex; align-items: center; gap: 12px; }
.toolbar .el-select { width: 180px; max-width: 60%; }
.ticket-detail { min-height: 80px; max-height: 65vh; overflow: auto; }
.ticket-detail h2 { margin: 0; font-size: 18px; overflow-wrap: anywhere; }
.update { padding: 12px 0; border-top: 1px solid var(--el-border-color-lighter); }
.message { white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
