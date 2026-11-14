<template>
  <div ref="containerRef" class="tinymce-editor">
    <Editor
      v-if="editorReady"
      :id="editorId"
      v-model="content"
      :init="initOptions"
      :disabled="readonly"
      @onEditorChange="handleChange"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import Editor from '@tinymce/tinymce-vue'
import tinymce from 'tinymce'
import type { RawEditorOptions } from 'tinymce'
import 'tinymce/icons/default'
import 'tinymce/themes/silver'
import 'tinymce/models/dom'
import 'tinymce/skins/ui/oxide/skin.min.css'
import contentUiCss from 'tinymce/skins/ui/oxide/content.min.css?inline'
import contentCss from 'tinymce/skins/content/default/content.min.css?inline'
import 'tinymce/plugins/advlist'
import 'tinymce/plugins/autolink'
import 'tinymce/plugins/code'
import 'tinymce/plugins/codesample'
import 'tinymce/plugins/fullscreen'
import 'tinymce/plugins/help'
import 'tinymce/plugins/help/js/i18n/keynav/en'
import 'tinymce/plugins/help/js/i18n/keynav/zh_CN'
import 'tinymce/plugins/image'
import 'tinymce/plugins/link'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/media'
import 'tinymce/plugins/preview'
import 'tinymce/plugins/searchreplace'
import 'tinymce/plugins/table'
import 'tinymce/plugins/wordcount'
import { uploadFile } from '@/request/upload'

interface Props {
  modelValue?: string
  height?: number
  readonly?: boolean
  toolbar?: string
  placeholder?: string
  menubar?: boolean | string
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: '',
  height: 420,
  readonly: false,
  toolbar:
    'undo redo | blocks | bold italic underline forecolor backcolor | alignleft aligncenter alignright alignjustify | bullist numlist outdent indent | image media | preview fullscreen code',
  placeholder: '',
  menubar: 'edit insert view format table',
  disabled: false
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'change', value: string): void
}>()

const containerRef = ref<HTMLElement>()
const editorReady = ref(false)
const editorId = `tinymce-${Math.random().toString(36).slice(2, 10)}`
const content = ref(props.modelValue)
const publicPath = import.meta.env.BASE_URL || '/'
const tinymceLicenseKey = String(import.meta.env.VITE_TINYMCE_LICENSE_KEY || '').trim()

watch(
  () => props.modelValue,
  value => {
    if (value !== content.value) {
      content.value = value || ''
    }
  }
)

const readonly = computed(() => props.readonly || props.disabled)

const filePickerCallback = (
  callback: (url: string, meta?: Record<string, any>) => void,
  _value: string,
  meta: { filetype?: string }
) => {
  const input = document.createElement('input')
  input.setAttribute('type', 'file')

  if (meta.filetype === 'image') {
    input.setAttribute('accept', 'image/*')
  }

  input.onchange = async () => {
    const file = input.files?.[0]
    if (!file) return
    const url = await uploadFile(file)
    callback(url, { title: file.name })
  }

  input.click()
}

const initOptions = computed<RawEditorOptions>(() => ({
  height: props.height,
  menubar: props.menubar,
  license_key: tinymceLicenseKey,
  menu: {
    insert: { title: '插入', items: 'image media' }
  },
  branding: false,
  statusbar: false,
  promotion: false,
  placeholder: props.placeholder,
  language: 'zh_CN',
  language_url: `${publicPath}tinymce/langs/zh_CN.js`,
  skin: false,
  content_css: [],
  content_style: contentUiCss + '\n' + contentCss,
  ui_container: containerRef.value,
  plugins: [
    'advlist',
    'autolink',
    'code',
    'codesample',
    'fullscreen',
    'help',
    'image',
    'link',
    'lists',
    'media',
    'preview',
    'searchreplace',
    'table',
    'wordcount'
  ],
  toolbar: props.toolbar,
  images_upload_handler: (blobInfo, progress): Promise<string> => {
    const blob = blobInfo.blob()
    const file = new File([blob], blobInfo.filename(), { type: blob.type })
    return uploadFile(file, { onProgress: progress })
  },
  file_picker_types: 'image media file',
  file_picker_callback: filePickerCallback,
  setup: editor => {
    editor?.on('init', () => {
      if (readonly.value) {
        editor.mode.set('readonly')
      }
    })
  }
}))

const handleAuxFocus = (event: FocusEvent) => {
  const target = event.target as HTMLElement | null
  if (!target) return
  if (target.closest('.tox-tinymce-aux, .tox-dialog, .tox-dialog-wrap, .moxman-window, .tam-assetmanager-root')) {
    event.stopImmediatePropagation()
  }
}

onMounted(() => {
  editorReady.value = true
  document.addEventListener('focusin', handleAuxFocus, true)
})

onBeforeUnmount(() => {
  document.removeEventListener('focusin', handleAuxFocus, true)
})

watch(readonly, value => {
  const editor = tinymce.get(editorId)
  if (!editor) return
  editor.mode.set(value ? 'readonly' : 'design')
})

const handleChange = (value: string) => {
  content.value = value
  emit('update:modelValue', value)
  emit('change', value)
}
</script>

<style>
.tox-tinymce-aux {
  z-index: 30000 !important;
}

.tox-dialog {
  z-index: 30001 !important;
}

.tox-dialog__backdrop {
  z-index: 30000 !important;
}

.tox-dialog * {
  pointer-events: auto;
}

.tox-dialog textarea,
.tox-dialog input {
  pointer-events: auto !important;
  user-select: text !important;
  -webkit-user-select: text !important;
}

.tox-dialog-wrap,
.tox-dialog-wrap * {
  pointer-events: auto !important;
}

.tox-dialog-wrap {
  position: fixed !important;
}

.tox-dialog-wrap ~ .el-overlay,
.tox-dialog-wrap ~ .v-modal {
  pointer-events: none !important;
}
</style>

<style scoped>
.tinymce-editor {
  width: 100%;
}

.tinymce-editor :deep(.tox-tinymce) {
  width: 100%;
  border-radius: 8px;
}
</style>
