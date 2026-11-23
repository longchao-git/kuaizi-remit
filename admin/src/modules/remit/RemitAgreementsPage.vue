<template>
  <section class="agreements">
    <h1>协议管理</h1>
    <el-card class="remit-card" shadow="never">
      <el-tabs v-model="activeKind">
        <el-tab-pane v-for="item in documents" :key="item.kind" :label="item.title" :name="item.kind">
          <el-alert v-if="item.error" :title="item.error" type="error" :closable="false" />
          <el-button v-if="!item.ready && !item.loading" @click="load(item)">重新加载</el-button>
          <el-form v-else v-loading="item.loading" label-position="top" @submit.prevent="saveDraft(item)">
            <p v-if="item.current">已发布版本 {{ item.current.version }} · {{ formatDate(item.current.published_at) }}</p>
            <p v-else>尚未发布</p>
            <p v-if="item.draft">草稿已保存 · {{ formatDate(item.draft.updated_at) }}</p>
            <p v-if="hasChanges(item)">有未保存修改</p>
            <el-form-item label="正文">
              <el-input v-model="item.body" type="textarea" :rows="16" maxlength="30000" show-word-limit
                :disabled="isBusy(item)" />
            </el-form-item>
            <div class="actions">
              <el-button type="primary" native-type="submit" :loading="item.saving"
                :disabled="!item.ready || isBusy(item) || !item.body.trim() || !hasChanges(item)">
                保存草稿
              </el-button>
              <el-button :loading="item.publishing" @click="publish(item)"
                :disabled="!item.ready || isBusy(item) || !item.body.trim() || item.body.trim() === item.current?.body">
                发布
              </el-button>
              <el-button :disabled="isBusy(item)" @click="reload(item)">重新加载</el-button>
            </div>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </section>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { HttpError } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import {
  publishAgreement, readAgreementEditor, saveAgreementDraft,
  type AgreementDraft, type AgreementKind, type PublishedAgreement,
} from './repository/remitAgreementRepository'

interface Editor {
  kind: AgreementKind
  title: string
  body: string
  current: PublishedAgreement | null
  draft: AgreementDraft | null
  savedBody: string
  ready: boolean
  loading: boolean
  saving: boolean
  publishing: boolean
  error: string
}

const activeKind = ref<Editor['kind']>('terms')
const documents = reactive<Editor[]>(([
  { kind: 'terms', title: '用户协议' },
  { kind: 'privacy', title: '隐私政策' },
] as const).map(item => ({
  ...item,
  body: '', current: null, draft: null, savedBody: '', ready: false,
  loading: true, saving: false, publishing: false, error: '',
})))
const dateFormat = new Intl.DateTimeFormat('zh-CN', { dateStyle: 'short', timeStyle: 'short', timeZone: 'Europe/Madrid' })

function formatDate(value: string) {
  return dateFormat.format(new Date(value)) + '（马德里）'
}

function isBusy(item: Editor) {
  return item.loading || item.saving || item.publishing
}

function hasChanges(item: Editor) {
  return item.body.trim() !== item.savedBody
}

function operationError(error: unknown, fallback: string) {
  return error instanceof HttpError && error.status === 409
    ? '草稿已在其他页面更新，请重新加载后再操作'
    : getErrorMessage(error, fallback)
}

async function load(item: Editor) {
  item.loading = true
  item.error = ''
  try {
    const result = await readAgreementEditor(item.kind)
    item.current = result.document
    item.draft = result.draft
    item.body = result.draft?.body ?? result.document?.body ?? ''
    item.savedBody = item.body
    item.ready = true
  } catch (error) {
    item.error = getErrorMessage(error, '读取协议失败，请重试')
  } finally {
    item.loading = false
  }
}

async function reload(item: Editor) {
  if (isBusy(item)) return
  if (hasChanges(item)) {
    try {
      await ElMessageBox.confirm('重新加载会替换当前未保存的修改。', '重新加载' + item.title, {
        confirmButtonText: '重新加载', cancelButtonText: '取消',
      })
    } catch {
      return
    }
  }
  await load(item)
}

async function saveDraft(item: Editor) {
  const body = item.body.trim()
  if (!item.ready || isBusy(item) || !body || !hasChanges(item)) return

  item.saving = true
  item.error = ''
  try {
    item.draft = await saveAgreementDraft(item.kind, body, item.draft?.revision ?? null)
    item.body = item.draft.body
    item.savedBody = item.body
    ElMessage.success('草稿已保存')
  } catch (error) {
    item.error = operationError(error, '草稿保存失败，请重试')
  } finally {
    item.saving = false
  }
}

async function publish(item: Editor) {
  const body = item.body.trim()
  if (!item.ready || isBusy(item) || !body || body === item.current?.body) return

  item.publishing = true
  item.error = ''
  try {
    await ElMessageBox.confirm('发布后，App 将显示本次正文。', '发布' + item.title, {
      confirmButtonText: '发布',
      cancelButtonText: '取消'
    })
    item.current = await publishAgreement(item.kind, body, item.draft?.revision ?? null)
    item.draft = null
    item.body = item.current.body
    item.savedBody = item.body
    ElMessage.success('已发布')
  } catch (error) {
    if (error !== 'cancel' && error !== 'close') {
      item.error = operationError(error, '发布失败，请重试')
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
.actions { display: flex; flex-wrap: wrap; gap: 12px; }
.actions .el-button + .el-button { margin-left: 0; }
</style>
