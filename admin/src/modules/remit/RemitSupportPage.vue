<template>
  <section class="support-page">
    <h1>客服消息</h1>
    <div class="conversation-toolbar">
      <el-checkbox v-model="unreadOnly" @change="loadConversations(1)">仅看未读</el-checkbox>
      <el-button :loading="listing" @click="reloadConversations()">刷新会话</el-button>
    </div>
    <el-alert v-if="listError" :title="listError" type="error" :closable="false" />
    <el-table v-loading="listing" :data="conversations" :empty-text="listError ? '读取失败' : '暂无会话'">
      <el-table-column label="会员" min-width="125" show-overflow-tooltip>
        <template #default="{ row }">{{ row.member_name }} · {{ row.member_id }}</template>
      </el-table-column>
      <el-table-column prop="last_message.body" label="最新消息" min-width="185" show-overflow-tooltip />
      <el-table-column label="未读" width="80">
        <template #default="{ row }">
          <el-tag v-if="row.unread_count" type="warning">{{ row.unread_count }}</el-tag>
          <span v-else>0</span>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="108">
        <template #default="{ row }">
          <el-button link type="primary" @click="selectConversation(row.member_id)">打开会话</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="total > 20" :current-page="page" :page-size="20" :total="total"
      layout="prev, pager, next" @current-change="loadConversations"
    />
    <el-form inline @submit.prevent="openConversation">
      <el-form-item label="会员编号">
        <el-input v-model.trim="memberInput" placeholder="输入会员编号" />
      </el-form-item>
      <el-button type="primary" native-type="submit" :disabled="!validMember">查看会话</el-button>
    </el-form>
    <template v-if="memberId">
      <p>会员 {{ memberId }} · {{ live ? '实时更新' : '可手动刷新' }}</p>
      <el-button :loading="loading" @click="refresh()">刷新消息</el-button>
      <el-alert v-if="errorMessage" :title="errorMessage" type="error" :closable="false" />
      <el-empty v-if="conversationReady && !messages.length" description="暂无消息" />
      <ul v-if="messages.length" ref="messageList" class="message-list" aria-live="polite">
        <li v-for="message in messages" :key="message.id">
          <strong>{{ message.sender === 'staff' ? '客服' : '会员' }}</strong>
          <time>{{ new Date(message.created_at).toLocaleString() }}</time>
          <p>{{ message.body }}</p>
        </li>
      </ul>
      <template v-if="conversationReady">
        <el-input v-model="draft" type="textarea" :rows="3" maxlength="500" placeholder="输入回复" />
        <el-button type="primary" :loading="sending" :disabled="!draft.trim()" @click="send">发送回复</el-button>
      </template>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { http } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import {
  getSupportHistory, listSupportConversations, markSupportRead, sendSupportReply,
  type Conversation, type SupportMessage
} from './repository/remitSupportRepository'

const memberInput = ref('')
const route = useRoute()
const router = useRouter()
const memberId = ref('')
const validMember = computed(() => /^[1-9]\d*$/.test(memberInput.value) && Number.isSafeInteger(Number(memberInput.value)))
const messages = ref<SupportMessage[]>([])
const messageList = ref<HTMLElement | null>(null)
const draft = ref('')
const errorMessage = ref('')
const sending = ref(false)
const live = ref(false)
const loading = ref(false)
const conversationReady = ref(false)
const conversations = ref<Conversation[]>([])
const unreadOnly = ref(false)
const page = ref(1)
const total = ref(0)
const listing = ref(false)
const listError = ref('')
let listVersion = 0
let sync = { cursor: null as number | null, read: 0, running: false, queued: false }
let retry: { id: string; body: string; member: string } | undefined
let socket: WebSocket | undefined
let reconnect: ReturnType<typeof setTimeout> | undefined
let stopped = false
let retryDelay = 1000

const channel = 'private-support.staff'
const socketUrl = import.meta.env.VITE_REVERB_URL
const socketKey = import.meta.env.VITE_REVERB_APP_KEY

async function loadConversations(next: number) {
  const version = ++listVersion
  listing.value = true
  listError.value = ''
  try {
    const result = await listSupportConversations(next, unreadOnly.value)
    if (version !== listVersion || stopped) return
    const last = Math.max(1, Math.ceil(result.total / result.page_size))
    if (next > last) {
      await loadConversations(last)
      return
    }
    conversations.value = result.list
    total.value = result.total
    page.value = result.page
  } catch (error) {
    if (version === listVersion && !stopped) listError.value = getErrorMessage(error, '读取会话失败，请刷新重试')
  } finally {
    if (version === listVersion && !stopped) listing.value = false
  }
}

function reloadConversations() {
  return loadConversations(page.value)
}

watch(() => messages.value.length, async () => {
  const list = messageList.value
  const nearEnd = !list || list.scrollHeight - list.clientHeight - list.scrollTop < 64
  await nextTick()
  if (nearEnd && messageList.value) messageList.value.scrollTop = messageList.value.scrollHeight
})

async function refresh() {
  const member = memberId.value
  if (!member) return
  const state = sync
  state.queued = true
  if (state.running) return
  state.running = true
  loading.value = true

  try {
    while (state.queued && state === sync) {
      state.queued = false
      const result = await getSupportHistory(member, state.cursor)
      if (state !== sync) return
      messages.value.push(...result.list)
      state.cursor = result.next_after_id ?? 0
      state.queued ||= result.has_more
      conversationReady.value = true
      errorMessage.value = ''
    }
    await nextTick()
    if (state === sync && document.visibilityState === 'visible' && state.cursor && state.cursor > state.read) {
      const id = state.cursor
      await markSupportRead(member, id)
      if (state !== sync) return
      state.read = id
      void reloadConversations()
    }
  } catch (error) {
    if (state === sync) errorMessage.value = getErrorMessage(error, '读取消息失败，请刷新重试')
  } finally {
    state.running = false
    if (state === sync) {
      loading.value = false
      if (state.queued) void refresh()
    }
  }
}

function refreshVisible() {
  if (document.visibilityState === 'visible') {
    void refresh()
    void reloadConversations()
  }
}

function openConversation() {
  if (!validMember.value) return
  if (memberId.value !== memberInput.value) {
    sync = { cursor: null, read: 0, running: false, queued: false }
    memberId.value = memberInput.value
    messages.value = []
    conversationReady.value = false
    errorMessage.value = ''
    draft.value = ''
    retry = undefined
  }
  void refresh()
}

function selectConversation(member: string) {
  if (route.query.member === member) {
    memberInput.value = member
    openConversation()
    return
  }
  void router.replace({ query: { ...route.query, member } })
}

watch(() => route.query.member, (member) => {
  if (typeof member === 'string' && /^[1-9]\d*$/.test(member)) {
    memberInput.value = member
    openConversation()
  }
}, { immediate: true })

async function send() {
  const member = memberId.value
  const body = draft.value.trim()
  if (!member || !conversationReady.value || !body || sending.value) return
  if (!retry || retry.member !== member || retry.body !== body) retry = { id: crypto.randomUUID(), member, body }
  const pending = retry
  sending.value = true
  try {
    await sendSupportReply(member, pending.id, body)
    if (retry === pending) retry = undefined
    if (memberId.value === member) {
      if (draft.value.trim() === body) draft.value = ''
      await refresh()
    }
    void loadConversations(1)
  } catch (error) {
    ElMessage.error(getErrorMessage(error, '发送失败，可重试'))
  } finally {
    sending.value = false
  }
}

function connect() {
  if (!socketUrl || !socketKey || stopped) return
  const connection = new WebSocket(`${socketUrl.replace(/\/$/, '')}/app/${encodeURIComponent(socketKey)}?protocol=7&client=web&version=1.0`)
  socket = connection
  connection.onclose = () => {
    live.value = false
    if (!stopped) {
      reconnect = setTimeout(connect, retryDelay)
      retryDelay = Math.min(retryDelay * 2, 30000)
    }
  }
  connection.onmessage = async ({ data }) => {
    try {
      const frame = JSON.parse(data)
      if (frame.event === 'pusher:connection_established') {
        const { socket_id } = JSON.parse(frame.data)
        const signed = await http.post('/broadcasting/auth', { socket_id, channel_name: channel }) as unknown as { auth: string }
        if (connection.readyState === WebSocket.OPEN) {
          connection.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: signed.auth } }))
        }
      } else if (frame.event === 'pusher_internal:subscription_succeeded') {
        live.value = true
        retryDelay = 1000
        void refresh()
        void reloadConversations()
      } else if (frame.event === 'pusher:ping') {
        connection.send(JSON.stringify({ event: 'pusher:pong', data: {} }))
      } else if (frame.event === 'support.message') {
        void reloadConversations()
          if (memberId.value) void refresh()
      } else if (frame.event === 'support.read') {
        void reloadConversations()
      }
    } catch {
      connection.close()
    }
  }
}

onMounted(() => {
  document.addEventListener('visibilitychange', refreshVisible)
  void loadConversations(1)
  connect()
})
onBeforeUnmount(() => {
  document.removeEventListener('visibilitychange', refreshVisible)
  stopped = true
  sync = { cursor: null, read: 0, running: false, queued: false }
  clearTimeout(reconnect)
  socket?.close()
})
</script>

<style scoped>
.support-page { display: grid; gap: 16px; padding: 24px 28px; max-width: 880px; }
.support-page h1, .support-page p { margin: 0; }
.support-page form { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.support-page form :deep(.el-form-item) { margin: 0; }
.support-page form :deep(.el-button) { height: 42px; }
.conversation-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.support-page > .el-button { justify-self: start; }
.message-list { display: grid; gap: 12px; list-style: none; padding: 0; margin: 0; max-height: 42vh; overflow-y: auto; }
.message-list li { padding: 12px; border: 1px solid var(--el-border-color); border-radius: 8px; }
.message-list time { margin-inline-start: 12px; color: var(--el-text-color-secondary); }
.message-list p { margin-top: 6px; white-space: pre-wrap; overflow-wrap: anywhere; }
</style>
