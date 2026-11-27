<template>
  <view class="body material-list" :class="{ 'is-editor': editorTask }">
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <template v-if="editorTask">
      <view class="material-editor-heading">
        <text class="heading material-editor-title">资金来源证明</text>
        <text class="description">{{ editorTask.instruction }}</text>
      </view>
      <view v-if="editorTask.can_submit" class="material-editor-fields">
        <view v-if="file" class="line material-file">
          <view class="material-file-label">
            <image src="/static/remit/document.svg" mode="aspectFit" aria-hidden="true" />
            <view class="material-file-name">
              <text class="block">{{ file.name }}</text>
              <text class="material-deadline">已选附件</text>
            </view>
          </view>
          <button class="inline-action" :disabled="busy || choosing" @tap="replaceFile()">移除附件</button>
        </view>
        <view class="material-upload">
          <text class="material-upload-plus" aria-hidden="true">+</text>
          <view class="material-upload-copy">
            <view class="attachment-actions">
              <!-- #ifdef APP-PLUS -->
              <button class="inline-action accent" :disabled="busy || choosing" @tap="choose">拍照或选图</button>
              <button class="inline-action accent" :disabled="busy || choosing" @tap="choosePdf">选择 PDF</button>
              <!-- #endif -->
              <!-- #ifndef APP-PLUS -->
              <button class="inline-action accent" :disabled="busy || choosing" @tap="choose">选择附件</button>
              <!-- #endif -->
            </view>
            <text class="material-deadline">{{ formats }}，附件可选，单个最大 75,000 字节</text>
          </view>
        </view>
        <view class="card material-reply-card">
          <text class="material-reply-title">补件回复</text>
          <textarea v-model="reply" maxlength="6000" class="material-reply" placeholder="填写回复"
            aria-label="填写补件回复" :disabled="busy" />
        </view>
      </view>
      <view class="line material-meta material-editor-meta">
        <text v-if="editorTask.order_reference">订单 {{ editorTask.order_reference }}</text>
        <text>截止 {{ formatRemitTimestamp(editorTask.deadline_at) }}</text>
      </view>
      <text v-if="!editorTask.can_submit" class="description" role="status">
        补件状态为{{ labels[editorTask.status] }}，当前无法提交。
      </text>
      <view v-else class="material-editor-footer">
        <button class="primary" :disabled="busy || choosing || !reply.trim()" @tap="submit(editorTask)">
          {{ busy ? '正在提交…' : '提交补件' }}
        </button>
      </view>
    </template>
    <template v-else>
      <text v-if="!busy && !displayedTasks.length && !error" class="description">暂无补件任务</text>
      <view v-if="displayedTasks.some(task => task.can_submit)" class="material-heading">
        <view class="material-heading-icon">
          <image src="/static/remit/document.svg" mode="aspectFit" aria-hidden="true" />
        </view>
        <text>需要补充资料</text>
        <text class="description material-heading-detail">请按下方任务要求补充资料。</text>
      </view>
      <view v-for="task in displayedTasks" :key="task.reference" class="card material-card">
        <view class="line material-meta">
          <text v-if="task.order_reference">订单 {{ task.order_reference }}</text>
          <text class="material-tag" :class="`material-tag-${task.status}`">{{ labels[task.status] }}</text>
        </view>
        <text class="material-instruction">{{ task.instruction }}</text>
        <text class="material-deadline">截止 {{ formatRemitTimestamp(task.deadline_at) }}</text>
        <button v-if="task.can_submit" class="primary material-continue" :disabled="busy || choosing" @tap="select(task)">
          补充资料
        </button>
      </view>
      <view v-if="pages > 1" class="line">
        <button class="inline-action" :disabled="busy || choosing || page <= 1" @tap="load(page - 1)">上一页</button>
        <text>{{ page }} / {{ pages }}</text>
        <button class="inline-action" :disabled="busy || choosing || page >= pages" @tap="load(page + 1)">下一页</button>
      </view>
      <button class="inline-action accent" :disabled="busy || choosing" @tap="load(page)">刷新</button>
    </template>
  </view>
</template>

<script setup lang="ts">
import { formatRemitTimestamp } from './previewOrder';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { BASE_URL } from '@/config/api';
import request, { UnauthorizedError } from '@/request/http';
import { sessionService } from '@/shared/session/application/SessionService';
// #ifdef APP-PLUS
import { pickPdf, releasePdf } from '@/uni_modules/remit-document-picker';
// #endif

interface Task {
  reference: string;
  order_reference: string | null;
  deadline_at: string;
  instruction: string;
  status: '6001' | '6002' | '6003';
  can_submit: boolean;
  server_now?: string;
  received_at?: number;
}

interface Attachment {
  path: string;
  name: string;
  cachePath?: string;
}

const emit = defineEmits<{ expired: []; leave: [] }>();
const props = defineProps<{ targetReference?: string }>();
const path = 'app/remittance/materials';
const options = { handleUnauthorized: false };
const labels = { '6001': '待提交', '6002': '已完成', '6003': '已过期' };
const items = ref<Task[]>([]);
const targetTask = ref<Task>();
const clock = ref(Date.now());
const displayedTasks = computed(() => {
  const target = targetTask.value;
  const tasks = target ? [items.value.find(task => task.reference === target.reference) ?? target,
    ...items.value.filter(task => task.reference !== target.reference)] : items.value;
  return tasks.map(task => {
    const expired = isExpired(task);
    return { ...task, status: expired ? '6003' as const : task.status, can_submit: task.can_submit && !expired };
  });
});
const file = ref<Attachment>();
const selected = ref('');
const editorTask = computed(() => displayedTasks.value.find(task => task.reference === selected.value));
const pageTitle = computed(() => editorTask.value ? '上传资料' : '补充资料');
const reply = ref('');
const busy = ref(false);
const choosing = ref(false);
const error = ref('');
const page = ref(1);
const pages = ref(0);
const maxAttachmentBytes = 75_000;
let formats = 'JPG、PNG';
// #ifdef H5 || APP-PLUS
formats = 'PDF、JPG、PNG';
// #endif
let active = true;
let confirming = false;
let uploadInProgress: Attachment | undefined;
let timer: ReturnType<typeof setInterval> | undefined;

function received(task: Task): Task {
  return { ...task, received_at: Date.now() };
}

function isExpired(task: Task): boolean {
  const server = Date.parse(task.server_now ?? '');
  const current = Number.isFinite(server) && task.received_at !== undefined
    ? server + Math.max(0, clock.value - task.received_at) : clock.value;
  return task.status === '6001' && Date.parse(task.deadline_at) <= current;
}

function release(attachment?: Attachment) {
  // #ifdef APP-PLUS
  if (attachment?.cachePath) {
    try {
      releasePdf(attachment.cachePath);
    } catch {
      // The plugin also prunes abandoned files from its private cache.
    }
  }
  // #endif
}

function replaceFile(attachment?: Attachment) {
  release(file.value);
  file.value = attachment;
}

function clearEditor() {
  selected.value = '';
  reply.value = '';
  replaceFile();
}

async function run(action: () => Promise<void>) {
  if (!active || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (!active) {
      return;
    }
    if (reason instanceof UnauthorizedError) emit('expired');
    else error.value = reason instanceof Error ? reason.message : '补件操作失败，请重试';
  } finally {
    busy.value = false;
  }
}

async function refreshTasks(next: number) {
  const result = await request.get<{ list: Task[]; page: number; total_pages: number }>(path, { page: next }, options);
  if (!active) {
    return;
  }
  items.value = result.list.map(received);
  page.value = result.page;
  pages.value = result.total_pages;
  if (props.targetReference) {
    const linked = result.list.find(task => task.reference === props.targetReference)
      ?? await request.get<Task>(`${path}/${encodeURIComponent(props.targetReference)}`, {}, options);
    if (!active) return;
    targetTask.value = received(linked);
  }
  if (selected.value && !displayedTasks.value.some(task => task.reference === selected.value && task.can_submit)) {
    clearEditor();
  }
}

function load(next: number) {
  if (!active || busy.value || choosing.value || confirming) return;
  if (next !== page.value && hasUnsubmitted()) {
    confirmDiscard(() => {
      clearEditor();
      void run(() => refreshTasks(next));
    });
    return;
  }
  return run(() => refreshTasks(next));
}

function select(task: Task) {
  if (!active || busy.value || choosing.value || !task.can_submit || task.reference === selected.value) return;
  confirmDiscard(() => {
    clearEditor();
    selected.value = task.reference;
    error.value = '';
  });
}

function hasUnsubmitted(): boolean {
  return Boolean(selected.value && (reply.value.trim() || file.value));
}

function confirmDiscard(action: () => void) {
  if (confirming) return;
  if (!hasUnsubmitted()) {
    action();
    return;
  }
  confirming = true;
  uni.showModal({
    title: '放弃本次补件编辑？', content: '未提交的回复和附件将丢失。',
    success: result => {
      if (result.confirm && active) {
        action();
      }
    },
    complete: () => { confirming = false; },
  });
}

function back(): boolean {
  if (busy.value || choosing.value || confirming) return true;
  if (!selected.value) return false;
  confirmDiscard(() => {
    if (props.targetReference) emit('leave');
    else {
      clearEditor();
      error.value = '';
    }
  });
  return true;
}

function choose() {
  if (busy.value || choosing.value) return;
  choosing.value = true;
  const reference = selected.value;
  error.value = '';
  const receive = (path: string, name: string, size: number) => {
    choosing.value = false;
    if (!active || selected.value !== reference) return;
    if (!path || !Number.isFinite(size) || size <= 0 || size > maxAttachmentBytes) {
      error.value = '请选择不超过 75,000 字节的文件';
      return;
    }
    replaceFile({ path, name });
  };
  const fail = (reason: { errMsg?: string }) => {
    choosing.value = false;
    if (active && selected.value === reference && !reason.errMsg?.toLowerCase().includes('cancel')) {
      error.value = '无法选取附件，请重试';
    }
  };
  // #ifdef H5
  // Some browsers dismiss the picker without a callback.
  choosing.value = false;
  uni.chooseFile({
    count: 1,
    type: 'all',
    extension: ['.pdf', '.jpg', '.jpeg', '.png'],
    fail,
    success(result) {
      const selectedFile = Array.isArray(result.tempFiles) ? result.tempFiles[0] : result.tempFiles;
      if (selectedFile && 'name' in selectedFile) {
        receive(selectedFile.path, selectedFile.name, selectedFile.size);
      }
    },
  });
  // #endif
  // #ifndef H5
  uni.chooseImage({
    count: 1,
    sizeType: ['compressed'],
    fail,
    success(result) {
      const selectedFile = Array.isArray(result.tempFiles) ? result.tempFiles[0] : result.tempFiles;
      if (selectedFile) {
        receive(selectedFile.path, selectedFile.path.split('/').pop() || '图片', selectedFile.size);
      } else {
        fail({});
      }
    },
  });
  // #endif
}

// #ifdef APP-PLUS
function choosePdf() {
  if (busy.value || choosing.value) return;
  choosing.value = true;
  const reference = selected.value;
  error.value = '';
  try {
    pickPdf(maxAttachmentBytes, (status, cachePath, name, size) => {
      choosing.value = false;
      const attachment = cachePath ? { path: 'file://' + cachePath, name, cachePath } : undefined;
      if (!active || selected.value !== reference || status === 'cancelled') {
        release(attachment);
        return;
      }
      if (status === 'ok' && attachment && size > 0 && size <= maxAttachmentBytes) {
        replaceFile(attachment);
        return;
      }
      release(attachment);
      const messages: Record<string, string> = {
        too_large: '请选择不超过 75,000 字节的 PDF',
        invalid: '文件内容与 PDF 格式不符，请重新选择',
      };
      error.value = messages[status] ?? '无法读取 PDF，请确认文件已下载后重试';
    });
  } catch {
    choosing.value = false;
    error.value = '无法打开系统文件选择器，请重试';
  }
}
// #endif

function upload(url: string, path: string, message: string): Promise<Task> {
  return new Promise((resolve, reject) => {
    uni.uploadFile({
      url: BASE_URL + url, filePath: path, name: 'document', formData: { reply: message },
      header: { Authorization: 'Bearer ' + sessionService.getToken() },
      success(response) {
        if (response.statusCode === 401) {
          reject(new UnauthorizedError());
          return;
        }
        try {
          const result = JSON.parse(response.data) as { code: number; msg: string; data: Task };
          if (result.code === 401) {
            reject(new UnauthorizedError(result.msg));
          } else if (response.statusCode >= 200 && response.statusCode < 300 && result.code === 0) {
            resolve(result.data);
          } else {
            reject(new Error(result.msg || '补件提交失败'));
          }
        } catch {
          reject(new Error('补件返回格式异常'));
        }
      },
      fail() { reject(new Error('上传失败，请使用原回复和附件重试')); },
    });
  });
}

function submit(task: Task) {
  if (choosing.value) return;
  clock.value = Date.now();
  if (!task.can_submit || isExpired(task)) {
    error.value = '补件任务已关闭或过期，请刷新查看';
    return;
  }
  return run(async () => {
    const url = path + '/' + task.reference;
    const attachment = file.value;
    uploadInProgress = attachment;
    try {
      const result = attachment ? await upload(url, attachment.path, reply.value.trim())
        : await request.post<Task>(url, { reply: reply.value.trim() }, options);
      if (active) {
        items.value = items.value.map(item => item.reference === task.reference ? received(result) : item);
        if (targetTask.value?.reference === result.reference) targetTask.value = received(result);
        clearEditor();
      }
    } catch (reason) {
      if (active && !(reason instanceof UnauthorizedError)) {
        try {
          await refreshTasks(page.value);
        } catch (refreshError) {
          if (refreshError instanceof UnauthorizedError) {
            throw refreshError;
          }
          // 刷新失败时保留提交错误和原资料，供用户重试。
        }
      }
      throw reason;
    } finally {
      uploadInProgress = undefined;
      if (!active) release(attachment);
    }
  });
}

onMounted(() => run(async () => {
  timer = setInterval(() => { clock.value = Date.now(); }, 1000);
  await refreshTasks(1);
  if (active && targetTask.value?.can_submit) selected.value = targetTask.value.reference;
}));
onUnmounted(() => {
  active = false;
  clearInterval(timer);
  if (file.value !== uploadInProgress) release(file.value);
});
defineExpose({ back, pageTitle });
</script>

<style scoped>
.material-list,
.material-card {
  display: grid;
  gap: 22rpx;
}
.material-list.is-editor {
  display: flex;
  flex: 1;
  flex-direction: column;
  gap: 24rpx;
  padding-bottom: calc(28rpx + env(safe-area-inset-bottom));
}
.material-editor-heading .description {
  margin-top: 10rpx;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.material-editor-fields {
  display: grid;
  gap: 20rpx;
}
.material-reply-card {
  display: grid;
  gap: 14rpx;
  padding: 24rpx 28rpx;
  border-color: #e9e9f0;
  border-radius: 36rpx;
  box-shadow: 0 2rpx 5rpx rgba(31, 35, 48, 0.035);
}
.material-reply-title {
  color: #111318;
  font-size: 28rpx;
  font-weight: 700;
}
.material-editor-meta {
  flex-wrap: wrap;
  overflow-wrap: anywhere;
}
.material-editor-footer {
  margin-top: auto;
  padding-top: 24rpx;
}
.material-editor-footer .primary:not([disabled]) {
  box-shadow: 0 10rpx 24rpx rgba(78, 61, 219, 0.21);
}
.material-heading {
  display: grid;
  justify-items: center;
  gap: 16rpx;
  padding: 36rpx 0 22rpx;
  color: #111318;
  font-size: 42rpx;
  font-weight: 700;
  text-align: center;
}
.material-heading-icon {
  display: grid;
  place-items: center;
  width: 260rpx;
  height: 260rpx;
  margin-bottom: 22rpx;
  border-radius: 86rpx;
  background: #fff2e9;
}
.material-heading-icon image {
  width: 112rpx;
  height: 112rpx;
}
.material-heading .material-heading-detail {
  margin-top: 0;
  font-weight: 400;
}
.material-card {
  padding: 28rpx 32rpx;
  overflow-wrap: anywhere;
}
.material-meta,
.material-deadline {
  color: #6d7180;
  font-size: 23rpx;
}
.material-tag {
  flex-shrink: 0;
  padding: 5rpx 14rpx;
  border-radius: 90rpx;
  color: #5544d8;
  background: #efedff;
}
.material-tag-6002 {
  color: #18704d;
  background: #e7f7ef;
}
.material-tag-6003 {
  color: #6d7180;
  background: #eff0f4;
}
.material-instruction {
  color: #111318;
  font-size: 28rpx;
  font-weight: 700;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.material-reply {
  box-sizing: border-box;
  width: 100%;
  height: 164rpx;
  padding: 20rpx;
  border: 1rpx solid #e7e7ef;
  border-radius: 24rpx;
  background: #f7f7fa;
}
.material-upload,
.material-file {
  padding: 18rpx 28rpx;
  border: 2rpx dashed #bdb4ff;
  border-radius: 32rpx;
}
.material-upload {
  display: grid;
  grid-template-columns: 86rpx minmax(0, 1fr);
  align-items: center;
  gap: 20rpx;
  min-height: 164rpx;
  box-sizing: border-box;
}
.material-upload-plus {
  display: grid;
  place-items: center;
  height: 86rpx;
  border-radius: 28rpx;
  background: #eeebff;
  color: var(--remit-accent);
  font-size: 42rpx;
  font-weight: 700;
}
.material-upload-copy {
  min-width: 0;
}
.material-file {
  border: 1rpx solid #83ba9d;
  background: #f6fcf8;
}
.material-file-label {
  display: flex;
  align-items: center;
  gap: 16rpx;
  min-width: 0;
}
.material-file-label image {
  flex-shrink: 0;
  width: 48rpx;
  height: 48rpx;
}
.material-file-name {
  color: #111318;
  font-weight: 700;
  overflow-wrap: anywhere;
}
.material-file button {
  flex-shrink: 0;
}
.attachment-actions {
  display: flex;
  flex-wrap: wrap;
  column-gap: 20rpx;
}
.material-continue {
  margin-top: 6rpx;
}
</style>
