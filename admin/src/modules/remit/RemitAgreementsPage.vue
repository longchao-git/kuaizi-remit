<template>
  <section class="agreements">
    <h1>协议管理</h1>
    <el-card class="remit-card" shadow="never">
      <el-tabs v-model="activeKind">
        <el-tab-pane v-for="item in documents" :key="item.kind" :label="item.title" :name="item.kind">
          <el-alert v-if="item.error" :title="item.error" type="error" :closable="false" />
          <el-button v-if="!item.ready && !item.loading" @click="load(item)">重新加载</el-button>
          <el-form v-else v-loading="item.loading" label-position="top" @submit.prevent="publish(item)">
            <p v-if="item.current">已发布版本 {{ item.current.version }} · {{ formatDate(item.current.published_at) }}</p>
            <p v-else>尚未发布</p>
            <el-form-item label="正文">
              <el-input v-model="item.body" type="textarea" :rows="16" maxlength="30000" show-word-limit
                :disabled="item.loading || item.publishing" />
            </el-form-item>
            <el-button type="primary" native-type="submit" :loading="item.publishing"
              :disabled="!item.ready || !item.body.trim() || item.body.trim() === item.current?.body">
              发布
            </el-button>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { get, post } from '@/request/http'
import { getErrorMessage } from '@/utils/error'

interface PublishedDocument {
  version: string
  body: string
  published_at: string
}

interface Editor {
  kind: 'terms' | 'privacy'
  title: string
  body: string
  current: PublishedDocument | null
  ready: boolean
  loading: boolean
  publishing: boolean
  error: string
}

const activeKind = ref<Editor['kind']>('terms')
const documents = reactive<Editor[]>([
  { kind: 'terms', title: '用户协议', body: '', current: null, ready: false, loading: true, publishing: false, error: '' },
  { kind: 'privacy', title: '隐私政策', body: '', current: null, ready: false, loading: true, publishing: false, error: '' }
])
const dateFormat = new Intl.DateTimeFormat('zh-CN', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Madrid' })

function formatDate(value: string) {
  return dateFormat.format(new Date(value)) + '（马德里）'
}

async function load(item: Editor) {
  item.loading = true
  item.error = ''
  try {
    const result = await get<{ document: PublishedDocument | null }>('/app/agreements/' + item.kind)
    item.current = result.document
    item.body = result.document?.body ?? ''
    item.ready = true
  } catch (error) {
    item.error = getErrorMessage(error, '读取协议失败，请重试')
  } finally {
    item.loading = false
  }
}

async function publish(item: Editor) {
  const body = item.body.trim()
  if (!item.ready || item.publishing || !body || body === item.current?.body) return

  item.publishing = true
  item.error = ''
  try {
    await ElMessageBox.confirm('发布后，App 将显示本次正文。', '发布' + item.title, {
      confirmButtonText: '发布',
      cancelButtonText: '取消'
    })
    item.current = await post<PublishedDocument>('/admin/remittance/agreements/' + item.kind, { body })
    item.body = item.current.body
    ElMessage.success('已发布')
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      item.error = getErrorMessage(error, '发布失败，请重试')
    }
  } finally {
    item.publishing = false
  }
}

onMounted(() => documents.forEach((item) => { void load(item) }))
</script>

<style scoped>
.agreements { display: grid; gap: 16px; }
.agreements h1 { margin: 0; }
.agreements .el-alert { margin-bottom: 12px; }
</style>
