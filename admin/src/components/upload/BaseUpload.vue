<template>
  <el-upload
    ref="elUploadRef"
    :show-file-list="false"
    :before-upload="internalBeforeUpload"
    :http-request="httpRequest ? internalHttpRequest : undefined"
    :accept="accept"
    :multiple="multiple"
    :drag="drag"
    class="base-upload"
  >
    <slot :uploading="uploading" :url="currentUrl" :files="currentFileList ?? []" :clear="clear" :progress="uploadProgress">
      <div v-if="preview && !multiple" class="default-upload-area">
        <div v-if="!currentUrl" class="upload-placeholder">
          <el-icon class="upload-icon"><Upload /></el-icon>
          <span class="upload-text">点击上传</span>
        </div>
        <div v-else class="upload-preview">
          <el-image :src="currentUrl" fit="cover" />
          <div class="upload-overlay" @click.stop="clear">
            <el-icon class="delete-icon"><Delete /></el-icon>
          </div>
        </div>
      </div>
      <el-button v-else type="primary" :loading="uploading">选择文件</el-button>
    </slot>
    <div v-if="uploading" class="upload-progress">
      <el-progress :percentage="uploadProgress" :stroke-width="6" />
    </div>
  </el-upload>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage, type UploadRequestOptions } from 'element-plus'
import { getErrorMessage } from '@/utils/error'

type UploadAdapter = (file: File, options?: { onProgress?: (percentage: number) => void }) => Promise<string>
type CustomHttpRequest = (options: UploadRequestOptions) => Promise<string>
type UploadProgressEvent = Parameters<UploadRequestOptions['onProgress']>[0]

const lazyImportUpload = async () => {
  const module = await import('@/request/upload')
  return module.uploadFile as UploadAdapter
}

interface Props {
  modelValue?: string
  url?: string
  fileList?: string[]
  accept?: string
  multiple?: boolean
  drag?: boolean
  maxSizeMb?: number | null
  preview?: boolean
  uploadAdapter?: UploadAdapter
  httpRequest?: CustomHttpRequest
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: undefined,
  url: undefined,
  fileList: undefined,
  accept: '',
  multiple: false,
  drag: false,
  maxSizeMb: null,
  preview: true,
  uploadAdapter: undefined,
  httpRequest: undefined
})

const emit = defineEmits<{
  'update:modelValue': [value?: string]
  'update:url': [value?: string]
  'update:fileList': [value?: string[]]
  success: [{ url: string, file: File }]
  error: [Error]
  'uploading-change': [boolean]
}>()

const currentUrl = ref<string | undefined>(props.modelValue ?? props.url)
const currentFileList = ref<string[] | undefined>(props.fileList)
const uploading = ref(false)
const uploadProgress = ref(0)
const elUploadRef = ref<unknown>()
let activeUploadCount = 0

watch(() => props.modelValue, value => { if (value !== undefined) currentUrl.value = value })
watch(() => props.url, value => { if (value !== undefined) currentUrl.value = value })
watch(() => props.fileList, value => { if (value !== undefined) currentFileList.value = value })

const isImageAccept = computed(() => (props.accept || '').split(',').some(value => value.trim().startsWith('image/')))

const clear = () => {
  currentUrl.value = ''
  currentFileList.value = []
  emit('update:modelValue', '')
  emit('update:url', '')
  emit('update:fileList', [])
  uploadProgress.value = 0
}

const setUploadProgress = (percentage: number) => {
  uploadProgress.value = Math.max(0, Math.min(100, Math.round(percentage)))
}

const startUpload = () => {
  activeUploadCount += 1
  uploadProgress.value = 0
  if (activeUploadCount === 1) {
    uploading.value = true
    emit('uploading-change', true)
  }
}

const finishUpload = () => {
  activeUploadCount = Math.max(0, activeUploadCount - 1)
  if (activeUploadCount === 0) {
    uploading.value = false
    emit('uploading-change', false)
  }
}

const validateFile = (file: File) => {
  if (props.maxSizeMb && file.size / 1024 / 1024 > props.maxSizeMb) {
    throw new Error(`文件大小不能超过 ${props.maxSizeMb}MB`)
  }
  if (isImageAccept.value && file.type && !file.type.startsWith('image/')) {
    throw new Error('只能上传图片文件')
  }
}

const normalizeUploadedUrl = (value: unknown) => {
  if (typeof value !== 'string' || !value.trim()) {
    throw new Error('上传成功但未返回文件地址')
  }
  return value.trim()
}

const applyUploadedUrl = (url: string, file: File) => {
  if (props.multiple) {
    const next = [...(currentFileList.value ?? []), url]
    currentFileList.value = next
    emit('update:fileList', next)
  } else {
    currentUrl.value = url
    emit('update:modelValue', url)
    emit('update:url', url)
  }
  emit('success', { url, file })
}

const reportUploadError = (error: unknown, fallback: string) => {
  const normalized = error instanceof Error ? error : new Error(getErrorMessage(error, fallback))
  ElMessage.error(getErrorMessage(normalized, fallback))
  emit('error', normalized)
  return normalized
}

const internalHttpRequest = async (options: UploadRequestOptions): Promise<string> => {
  const request = props.httpRequest
  if (!request) throw new Error('未配置自定义上传请求')

  startUpload()
  const wrappedOptions: UploadRequestOptions = {
    ...options,
    onProgress: (event: UploadProgressEvent) => {
      setUploadProgress(Number(event.percent || 0))
      options.onProgress(event)
    }
  }

  try {
    const url = normalizeUploadedUrl(await request(wrappedOptions))
    setUploadProgress(100)
    applyUploadedUrl(url, options.file)
    return url
  } catch (error) {
    uploadProgress.value = 0
    throw reportUploadError(error, '上传失败')
  } finally {
    finishUpload()
  }
}

const internalBeforeUpload = async (file: File) => {
  // 无论走内置适配器还是自定义 httpRequest，都必须先经过同一套前置校验。
  try {
    validateFile(file)
  } catch (error) {
    reportUploadError(error, '文件校验失败')
    return false
  }

  if (props.httpRequest) return true

  startUpload()
  try {
    const adapter = props.uploadAdapter ?? await lazyImportUpload()
    const url = normalizeUploadedUrl(await adapter(file, { onProgress: setUploadProgress }))
    setUploadProgress(100)
    applyUploadedUrl(url, file)
  } catch (error) {
    uploadProgress.value = 0
    reportUploadError(error, '上传失败')
  } finally {
    finishUpload()
  }
  return false
}
</script>

<style scoped>
.base-upload { display: inline-block; }
.upload-progress { width: 100%; margin-top: 8px; pointer-events: none; }
.default-upload-area {
  width: 120px;
  height: 120px;
  border: 1px dashed #d9d9d9;
  border-radius: 8px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #f5f7fa;
  transition: all 0.3s ease;
}
.default-upload-area:hover { border-color: var(--omc-color-primary); }
.upload-placeholder {
  text-align: center;
  color: #909399;
  font-size: 14px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}
.upload-icon { font-size: 28px; color: #8c8c8c; margin-bottom: 6px; }
.upload-text { color: #8c8c8c; font-size: 13px; font-weight: 500; }
.upload-preview { width: 100%; height: 100%; position: relative; border-radius: 8px; overflow: hidden; }
.upload-preview :deep(.el-image) { display: block; width: 100%; height: 100%; }
.upload-overlay {
  position: absolute;
  top: 0;
  right: 0;
  width: 28px;
  height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.45);
}
.delete-icon { font-size: 16px; color: #fff; }
</style>
