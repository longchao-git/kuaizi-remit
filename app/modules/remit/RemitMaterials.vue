<template>
  <view class="body material-list">
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-if="!busy && !items.length && !error" class="description">暂无补件任务</text>
    <view v-for="task in items" :key="task.reference" class="card material-card">
      <text>{{ task.instruction }}</text>
      <text class="description" v-if="task.order_reference">订单 {{ task.order_reference }}</text>
      <text class="description">截止 {{ new Date(task.deadline_at).toLocaleString() }}</text>
      <text class="description">{{ labels[task.status] }}</text>
      <template v-if="selected === task.reference && task.can_submit">
        <textarea v-model="reply" maxlength="6000" class="material-reply" placeholder="填写回复" :disabled="busy" />
        <text class="description">附件可选。{{ formats }}，单个最大 75,000 字节。</text>
        <!-- #ifdef APP-PLUS -->
        <view class="attachment-actions">
          <button class="inline-action" :disabled="busy || choosing" @tap="choose">拍照或选图</button>
          <button class="inline-action" :disabled="busy || choosing" @tap="choosePdf">选择 PDF</button>
        </view>
        <!-- #endif -->
        <!-- #ifndef APP-PLUS -->
        <button class="inline-action" :disabled="busy || choosing" @tap="choose">选择附件</button>
        <!-- #endif -->
        <text v-if="file" class="description">{{ file.name }}</text>
        <button v-if="file" class="inline-action" :disabled="busy || choosing" @tap="replaceFile()">移除附件</button>
        <button class="primary" :disabled="busy || choosing || !reply.trim()" @tap="submit(task)">提交补件</button>
      </template>
      <button v-else-if="task.can_submit" class="inline-action accent" :disabled="busy || choosing" @tap="select(task)">
        补充资料
      </button>
    </view>
    <view v-if="pages > 1" class="line">
      <button class="inline-action" :disabled="busy || choosing || page <= 1" @tap="load(page - 1)">上一页</button>
      <text>{{ page }} / {{ pages }}</text>
      <button class="inline-action" :disabled="busy || choosing || page >= pages" @tap="load(page + 1)">下一页</button>
    </view>
    <button class="inline-action accent" :disabled="busy || choosing" @tap="load(page)">刷新</button>
  </view>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
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
}

interface Attachment {
  path: string;
  name: string;
  cachePath?: string;
}

const emit = defineEmits<{ expired: [] }>();
const path = 'app/remittance/materials';
const options = { handleUnauthorized: false };
const labels = { '6001': '待提交', '6002': '已完成', '6003': '已过期' };
const items = ref<Task[]>([]);
const file = ref<Attachment>();
const selected = ref('');
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
let uploadInProgress: Attachment | undefined;

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

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (reason instanceof UnauthorizedError) emit('expired');
    else error.value = reason instanceof Error ? reason.message : '补件操作失败，请重试';
  } finally {
    busy.value = false;
  }
}

function load(next: number) {
  return run(async () => {
    const result = await request.get<{ list: Task[]; page: number; total_pages: number }>(path, { page: next }, options);
    items.value = result.list;
    page.value = result.page;
    pages.value = result.total_pages;
    if (selected.value && !result.list.some(task => task.reference === selected.value && task.can_submit)) {
      selected.value = '';
      replaceFile();
      reply.value = '';
    }
  });
}

function select(task: Task) {
  if (busy.value || choosing.value) return;
  selected.value = task.reference;
  reply.value = '';
  replaceFile();
  error.value = '';
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
          if (active) emit('expired');
          reject(new Error('登录已过期'));
          return;
        }
        try {
          const result = JSON.parse(response.data) as { code: number; msg: string; data: Task };
          if (result.code === 0) resolve(result.data);
          else reject(new Error(result.msg || '补件提交失败'));
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
  return run(async () => {
    const url = path + '/' + task.reference;
    const attachment = file.value;
    uploadInProgress = attachment;
    try {
      const result = attachment ? await upload(url, attachment.path, reply.value.trim())
        : await request.post<Task>(url, { reply: reply.value.trim() }, options);
      if (active) {
        items.value = items.value.map(item => item.reference === task.reference ? result : item);
        selected.value = '';
        replaceFile();
        reply.value = '';
      }
    } finally {
      uploadInProgress = undefined;
      if (!active) release(attachment);
    }
  });
}

onMounted(() => load(1));
onUnmounted(() => {
  active = false;
  if (file.value !== uploadInProgress) release(file.value);
});
</script>

<style scoped>
.material-list, .material-card { display: grid; gap: 16rpx; }
.material-card { padding: 22rpx; overflow-wrap: anywhere; }
.material-reply { box-sizing: border-box; width: 100%; padding: 16rpx; min-height: 120rpx; background: #f2f2f2; border-radius: 10rpx; }
.attachment-actions { display: flex; flex-wrap: wrap; gap: 12rpx; }
</style>
