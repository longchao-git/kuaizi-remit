<template>
  <section v-if="!selected" class="members-page">
    <h1>会员中心</h1>
    <el-form class="member-search" inline @submit.prevent="search">
      <el-form-item label="关键词">
        <el-input v-model.trim="input" maxlength="100" placeholder="编号、姓名、邮箱或 +34 手机号" clearable />
      </el-form-item>
      <el-button type="primary" native-type="submit" :loading="loading">查询</el-button>
    </el-form>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" />
    <el-card shadow="never">
      <template #header><h2>会员列表</h2></template>
      <el-table scrollbar-always-on v-loading="loading" :data="rows" :empty-text="loadError ? '读取失败' : '暂无会员'">
        <el-table-column prop="id" label="会员编号" min-width="100" />
        <el-table-column prop="name" label="姓名" min-width="140" show-overflow-tooltip />
        <el-table-column prop="email" label="邮箱" min-width="180" show-overflow-tooltip />
        <el-table-column prop="masked_phone" label="手机号" min-width="138" />
        <el-table-column label="身份认证" min-width="120">
          <template #default="{ row }">{{ kycStatus(row) }}</template>
        </el-table-column>
        <el-table-column label="创建时间" min-width="155">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" :width="100 + (canSupport ? 66 : 0) + (canNotify ? 78 : 0)" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openDetail(row.id)">查看详情</el-button>
            <el-button v-if="canSupport" link type="primary" @click="openConversation(row.id)">查看会话</el-button>
            <el-button v-if="canNotify" link type="primary" @click="openNotify(row)">发站内通知</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination class="member-pagination"
        v-if="total > pageSize" :current-page="page" :page-size="pageSize" :total="total"
        :disabled="loading" layout="prev, pager, next" @current-change="load"
      />
    </el-card>

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
  <section v-else class="members-page">
    <header class="member-heading">
      <h1>会员详情</h1>
      <el-button @click="returnToMembers">返回会员中心</el-button>
    </header>
    <el-alert v-if="detailError" :title="detailError" type="error" :closable="false" />
    <div v-loading="detailLoading" class="member-detail">
      <template v-if="detail">
        <el-card shadow="never">
          <template #header><h2>用户概览</h2></template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="会员编号">{{ detail.id }}</el-descriptions-item>
            <el-descriptions-item label="姓名">{{ detail.name }}</el-descriptions-item>
            <el-descriptions-item label="邮箱">{{ detail.email || '—' }}</el-descriptions-item>
            <el-descriptions-item label="手机号">{{ detail.masked_phone || '—' }}</el-descriptions-item>
            <el-descriptions-item label="注册时间（马德里）">{{ formatDate(detail.created_at) }}</el-descriptions-item>
          </el-descriptions>
        </el-card>
        <el-card shadow="never">
          <template #header><h2>身份认证</h2></template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="当前状态">
              <el-tag :type="detail.kyc_code === '5004' ? 'success' : 'info'">{{ kycStatus(detail) }}</el-tag>
            </el-descriptions-item>
          </el-descriptions>
          <div v-if="canViewOrders || canSupport" class="member-actions">
            <el-button v-if="canViewOrders" link type="primary" @click="openMemberOrders(detail.id)">查看汇款订单</el-button>
            <el-button v-if="canSupport" link type="primary" @click="openConversation(detail.id)">查看会话</el-button>
          </div>
        </el-card>
      </template>
    </div>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@/stores/user'
import { getErrorMessage } from '@/utils/error'
import { listMembers, sendMemberNotice, type MemberRow, type NoticeDraft } from './repository/remitOperationsRepository'

const router = useRouter()
const route = useRoute()
const staff = useUserStore()
const canSupport = computed(() => staff.permissions.includes('remit:support:reply'))
const canNotify = computed(() => staff.permissions.includes('remit:notifications:send'))
const canViewOrders = computed(() => staff.permissions.includes('remit:orders:view'))
const kycLabels: Record<string, string> = { '5001': '待认证', '5002': '审核中', '5003': '需更新', '5004': '已通过' }
const dateTime = new Intl.DateTimeFormat('zh-CN', {
  timeZone: 'Europe/Madrid', dateStyle: 'short', timeStyle: 'short', hour12: false
})

function kycStatus(member: MemberRow): string {
  if (!member.kyc_registered) return '未发起'
  return (member.kyc_code && kycLabels[member.kyc_code]) || '待同步'
}
const rows = ref<MemberRow[]>([])
const selected = computed(() => typeof route.query.member === 'string' ? route.query.member : '')
const detail = ref<MemberRow>()
const detailLoading = ref(false)
const detailError = ref('')
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
let detailVersion = 0

function formatDate(value: string | null) {
  return value ? dateTime.format(new Date(value)) : '—'
}

function openDetail(member: string) {
  void router.push({ query: { ...route.query, member }, state: { fromRemitMemberList: true } })
}

function returnToMembers() {
  if (window.history.state?.fromRemitMemberList) {
    router.back()
    return
  }
  void router.replace({ query: { ...route.query, member: undefined } })
}

function openMemberOrders(member: string) {
  void router.push({ path: '/system/remittance/orders', query: { member_id: member } })
}

watch(selected, async (member) => {
  const version = ++detailVersion
  detail.value = undefined
  detailError.value = ''
  detailLoading.value = false
  if (!member || route.path !== '/system/remittance/members') return
  if (!/^[1-9]\d{0,18}$/.test(member)) {
    detailError.value = '会员编号无效'
    return
  }
  detailLoading.value = true
  try {
    const result = await listMembers(1, member)
    if (version !== detailVersion) return
    detail.value = result.list.find((row) => row.id === member)
    if (!detail.value) detailError.value = '会员不存在'
  } catch (reason) {
    if (version === detailVersion) detailError.value = getErrorMessage(reason, '读取会员详情失败')
  } finally {
    if (version === detailVersion) detailLoading.value = false
  }
}, { immediate: true })

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
.members-page { display: grid; gap: 16px; }
.members-page h1 { margin: 0; }
.members-page h2 { margin: 0; font-size: 16px; }
.member-heading { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.member-detail { display: grid; gap: 16px; grid-template-columns: repeat(2, minmax(0, 1fr)); min-height: 68px; align-items: start; }
.member-detail :deep(.el-descriptions__content) { overflow-wrap: anywhere; }
.member-actions { display: flex; flex-wrap: wrap; gap: 8px 16px; margin-top: 12px; }
.member-actions .el-button + .el-button { margin-left: 0; }
.member-pagination { margin-top: 12px; }
.member-search { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.member-search :deep(.el-form-item) { margin: 0; }
.member-search :deep(.el-input) { width: 230px; }
.notice-form :deep(.el-input), .notice-form :deep(.el-textarea) { width: 100%; }
.dialog-actions { display: flex; justify-content: flex-end; margin-top: 16px; }
@media (max-width: 1050px) {
  .member-detail { grid-template-columns: minmax(0, 1fr); }
}
</style>
