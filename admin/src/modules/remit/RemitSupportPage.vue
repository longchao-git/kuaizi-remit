<template>
  <section class="support-page">
    <h1>客服工作台</h1>
    <div class="support-workspace">
      <el-card class="conversation-panel remit-card" shadow="never">
        <template #header><h2>会话列表</h2></template>
        <div class="conversation-toolbar">
          <el-checkbox v-model="unreadOnly" @change="loadConversations(1)">仅看未读</el-checkbox>
          <el-button :loading="listing" @click="reloadConversations()">刷新会话</el-button>
        </div>
        <el-alert v-if="listError" :title="listError" type="error" :closable="false" />
        <div v-loading="listing" class="conversation-content">
          <ul v-if="conversations.length" class="conversation-list">
            <li v-for="item in conversations" :key="item.member_id">
              <button type="button" class="conversation"
                :class="{ 'is-current': item.member_id === memberId }"
                :aria-current="item.member_id === memberId ? 'true' : undefined"
                :aria-label="`打开会话 · ${item.member_name} · 会员 ${item.member_id}`"
                @click="selectConversation(item.member_id)">
                <span class="conversation-avatar" aria-hidden="true">{{ item.member_name.slice(0, 1) || '会' }}</span>
                <span class="conversation-summary">
                  <strong>{{ item.member_name }} · {{ item.member_id }}</strong>
                  <span class="conversation-excerpt" :title="item.last_message.body">{{ item.last_message.body }}</span>
                </span>
                <el-tag v-if="item.unread_count" size="small" type="warning">{{ item.unread_count }} 未读</el-tag>
                <span v-else class="conversation-read">已读</span>
              </button>
            </li>
          </ul>
          <el-empty v-else-if="!listError && !listing" description="暂无会话" :image-size="64" />
        </div>
        <el-pagination
          v-if="total > 20" :current-page="page" :page-size="20" :total="total" :pager-count="5"
          layout="prev, pager, next" @current-change="loadConversations"
        />
        <el-form inline class="member-lookup" @submit.prevent="openConversation">
          <el-form-item label="会员编号">
            <el-input v-model.trim="memberInput" placeholder="输入会员编号" />
          </el-form-item>
          <el-button type="primary" native-type="submit" :disabled="!validMember">查看会话</el-button>
        </el-form>
      </el-card>

      <el-card class="chat-panel remit-card" shadow="never">
        <template #header>
          <div class="chat-header">
            <div>
              <h2>会话详情</h2>
              <p v-if="memberId" class="chat-meta">
                {{ activeConversation?.member_name || '会员' }} · {{ memberId }} · {{ live ? '实时更新' : '可手动刷新' }}
              </p>
            </div>
            <el-button v-if="memberId" :loading="loading" @click="refresh()">刷新消息</el-button>
          </div>
        </template>
        <el-alert v-if="errorMessage" :title="errorMessage" type="error" :closable="false" />
        <div v-loading="loading" class="chat-thread">
          <el-empty v-if="!memberId" description="请选择会话" :image-size="80" />
          <el-empty v-else-if="conversationReady && !messages.length" description="暂无消息" :image-size="80" />
          <ul v-if="messages.length" ref="messageList" class="message-list" aria-live="polite">
            <li v-for="message in messages" :key="message.id"
              :class="{ 'message--staff': message.sender === 'staff' }">
              <strong>{{ message.sender === 'staff' ? '客服' : '会员' }}</strong>
              <time>{{ new Date(message.created_at).toLocaleString() }}</time>
              <p>{{ message.body }}</p>
            </li>
          </ul>
        </div>
        <div v-if="conversationReady" class="chat-composer">
          <el-input v-model="draft" type="textarea" :rows="3" maxlength="500"
            aria-label="输入回复" placeholder="输入回复" />
          <el-button type="primary" :loading="sending" :disabled="!draft.trim()" @click="send">发送回复</el-button>
        </div>
      </el-card>
    </div>
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
const activeConversation = computed(() => conversations.value.find((item) => item.member_id === memberId.value))
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
.support-page { display: grid; gap: 16px; }
.support-page h1, .support-page p { margin: 0; }
.support-workspace { display: grid; grid-template-columns: minmax(300px, 2fr) minmax(0, 3fr); gap: 16px; align-items: start; }
.conversation-panel :deep(.el-card__body), .chat-panel :deep(.el-card__body) { display: grid; gap: 12px; }
.conversation-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.conversation-content { min-height: 100px; max-height: 48vh; overflow: auto; }
.conversation-list { display: grid; gap: 3px; list-style: none; padding: 0; margin: 0; }
.conversation {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 12px 10px;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: var(--omc-color-text);
  text-align: start;
  cursor: pointer;
}
.conversation:hover { background: var(--omc-color-surface-soft); }
.conversation.is-current { background: var(--omc-color-primary-soft); border-color: var(--omc-color-primary-soft-border); }
.conversation:focus-visible { outline: 2px solid var(--omc-color-primary); outline-offset: -2px; }
.conversation-avatar { display: grid; place-items: center; width: 30px; height: 30px; flex: 0 0 auto; border-radius: 50%; background: var(--omc-color-primary-soft); color: var(--omc-color-primary); }
.conversation-summary { display: grid; flex: 1; min-width: 0; gap: 4px; }
.conversation-summary strong, .conversation-excerpt { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.conversation-excerpt, .conversation-read, .chat-meta { color: var(--omc-color-text-secondary); font-size: 12px; }
.member-lookup { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding-top: 12px; border-top: 1px solid var(--omc-color-border-light); }
.member-lookup :deep(.el-form-item) { flex: 1; min-width: 176px; margin: 0; }
.member-lookup :deep(.el-button) { height: var(--omc-control-height); }
.chat-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 8px; }
.chat-meta { padding-top: 4px; }
.chat-thread { min-height: 180px; background: var(--omc-color-surface-soft); border-radius: 8px; }
.message-list { display: grid; gap: 12px; list-style: none; padding: 16px; margin: 0; max-height: 48vh; overflow-y: auto; }
.message-list li { justify-self: start; min-width: 0; max-width: 85%; padding: 12px; border: 1px solid var(--el-border-color); border-radius: 8px; background: var(--omc-color-surface); }
.message-list .message--staff { justify-self: end; background: var(--omc-color-primary-soft); }
.message-list time { margin-inline-start: 12px; color: var(--el-text-color-secondary); }
.message-list p { margin-top: 6px; white-space: pre-wrap; overflow-wrap: anywhere; }
.chat-composer { display: grid; justify-items: end; gap: 10px; }
@media (max-width: 950px) {
  .support-workspace { grid-template-columns: minmax(0, 1fr); }
  .conversation-content { max-height: 33vh; }
}
</style>
