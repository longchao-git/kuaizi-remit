<template>
  <section class="members-page">
    <h1>会员中心</h1>
    <el-form class="member-search" inline @submit.prevent="search">
      <el-form-item label="关键词">
        <el-input v-model.trim="input" maxlength="100" placeholder="编号、姓名、邮箱或 +34 手机号" clearable />
      </el-form-item>
      <el-button type="primary" native-type="submit" :loading="loading">查询</el-button>
    </el-form>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" />
    <el-table scrollbar-always-on v-loading="loading" :data="rows" :empty-text="loadError ? '读取失败' : '暂无会员'">
      <el-table-column prop="id" label="会员编号" min-width="100" />
      <el-table-column prop="name" label="姓名" min-width="140" show-overflow-tooltip />
      <el-table-column prop="email" label="邮箱" min-width="180" show-overflow-tooltip />
      <el-table-column prop="masked_phone" label="手机号" min-width="138" />
      <el-table-column label="身份认证" min-width="120">
        <template #default="{ row }">{{ kycStatus(row) }}</template>
      </el-table-column>
      <el-table-column label="创建时间" min-width="155">
        <template #default="{ row }">{{ row.created_at ? new Date(row.created_at).toLocaleString() : '—' }}</template>
      </el-table-column>
      <el-table-column v-if="canNotify || canSupport" label="操作" width="184">
        <template #default="{ row }">
          <el-button v-if="canSupport" link type="primary" @click="openConversation(row.id)">查看会话</el-button>
          <el-button v-if="canNotify" link type="primary" @click="openNotify(row)">发站内通知</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="total > pageSize" :current-page="page" :page-size="pageSize" :total="total"
      :disabled="loading" layout="prev, pager, next" @current-change="load"
    />

    <el-dialog v-model="dialogOpen" title="发送站内通知" width="min(520px, 94vw)" :before-close="closeDialog">
      <el-form v-if="recipient" class="notice-form" label-position="top" @submit.prevent="send">
        <el-form-item label="接收会员"><span>{{ recipient.name }} · {{ recipient.id }}</span></el-form-item>
        <el-form-item label="通知标题">
          <el-input v-model="title" maxlength="80" :disabled="sending" />
        </el-form-item>
        <el-form-item label="通知内容">
          <el-input v-model="body" type="textarea" :rows="4" maxlength="500" :disabled="sending" />
        </el-form-item>
        <el-alert v-if="sendError" :title="sendError" type="error" :closable="false" />
        <div class="dialog-actions">
          <el-button :disabled="sending" @click="dialogOpen = false">取消</el-button>
          <el-button type="primary" native-type="submit" :disabled="!title.trim() || !body.trim()" :loading="sending">发送</el-button>
        </div>
      </el-form>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@/stores/user'
import { getErrorMessage } from '@/utils/error'
import { listMembers, sendMemberNotice, type MemberRow, type NoticeDraft } from './repository/remitOperationsRepository'

const router = useRouter()
const staff = useUserStore()
const canSupport = computed(() => staff.permissions.includes('remit:support:reply'))
const canNotify = computed(() => staff.permissions.includes('remit:notifications:send'))
const kycLabels: Record<string, string> = { '5001': '待认证', '5002': '审核中', '5003': '需更新', '5004': '已通过' }

function kycStatus(member: MemberRow): string {
  if (!member.kyc_registered) return '未发起'
  return (member.kyc_code && kycLabels[member.kyc_code]) || '待同步'
}
const rows = ref<MemberRow[]>([])
const input = ref('')
const page = ref(1)
const total = ref(0)
const pageSize = ref(20)
const loading = ref(false)
const loadError = ref('')
const dialogOpen = ref(false)
const recipient = ref<MemberRow>()
const title = ref('')
const body = ref('')
const sending = ref(false)
const sendError = ref('')
let keyword = ''
let pending: { member: string; draft: NoticeDraft } | undefined

async function load(next: number) {
  if (loading.value) return
  loading.value = true
  loadError.value = ''
  try {
    const result = await listMembers(next, keyword)
    rows.value = result.list
    page.value = result.page
    total.value = result.total
    pageSize.value = result.page_size
  } catch (error) {
    loadError.value = getErrorMessage(error, '读取会员失败，请重试')
  } finally {
    loading.value = false
  }
}

function search() {
  if (loading.value) return
  keyword = input.value
  rows.value = []
  total.value = 0
  void load(1)
}

function openConversation(member: string) {
  void router.push({ path: '/system/support/messages', query: { member } })
}

function openNotify(member: MemberRow) {
  recipient.value = member
  title.value = pending?.member === member.id ? pending.draft.title : ''
  body.value = pending?.member === member.id ? pending.draft.body : ''
  sendError.value = ''
  dialogOpen.value = true
}

function closeDialog(done: () => void) {
  if (!sending.value) done()
}

async function send() {
  const member = recipient.value?.id
  const messageTitle = title.value.trim()
  const messageBody = body.value.trim()
  if (!canNotify.value || !member || !messageTitle || !messageBody || sending.value) return

  sending.value = true
  sendError.value = ''
  try {
    if (pending?.member !== member || pending.draft.title !== messageTitle || pending.draft.body !== messageBody) {
      pending = { member, draft: { request_id: crypto.randomUUID(), title: messageTitle, body: messageBody } }
    }
    await sendMemberNotice(member, pending.draft)
    pending = undefined
    dialogOpen.value = false
    ElMessage.success('已发送站内通知')
  } catch (error) {
    sendError.value = getErrorMessage(error, '发送失败，请重试')
  } finally {
    sending.value = false
  }
}

onMounted(() => void load(1))
</script>

<style scoped>
.members-page { display: grid; gap: 16px; padding: 24px 28px; }
.members-page h1 { margin: 0; }
.member-search { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.member-search :deep(.el-form-item) { margin: 0; }
.member-search :deep(.el-input) { width: 230px; }
.notice-form :deep(.el-input), .notice-form :deep(.el-textarea) { width: 100%; }
.dialog-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
</style>
