<template>
  <el-dialog
    v-bind="$attrs"
    :model-value="visible"
    :title="title"
    :width="width"
    :close-on-click-modal="closeOnClickModal"
    :close-on-press-escape="closeOnPressEscape"
    :append-to-body="appendToBody"
    :destroy-on-close="destroyOnClose"
    :trap-focus="trapFocus"
    :lock-scroll="lockScroll"
    :top="top"
    class="form-dialog"
    @update:model-value="$emit('update:visible', $event)"
    @closed="$emit('closed')"
  >
    <div class="form-dialog__content">
      <slot />
    </div>

    <template v-if="showFooter" #footer>
      <slot name="footer">
        <el-button v-if="showCancel" :disabled="loading" @click="$emit('update:visible', false)">
          {{ cancelText }}
        </el-button>
        <el-button v-if="showConfirm" type="primary" :loading="loading" @click="$emit('confirm')">
          {{ confirmText }}
        </el-button>
      </slot>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
defineOptions({ inheritAttrs: false })

interface Props {
  visible: boolean
  title?: string
  width?: string | number
  loading?: boolean
  showFooter?: boolean
  showCancel?: boolean
  showConfirm?: boolean
  cancelText?: string
  confirmText?: string
  closeOnClickModal?: boolean
  closeOnPressEscape?: boolean
  appendToBody?: boolean
  destroyOnClose?: boolean
  trapFocus?: boolean
  lockScroll?: boolean
  top?: string
}

withDefaults(defineProps<Props>(), {
  title: '',
  width: '640px',
  loading: false,
  showFooter: true,
  showCancel: true,
  showConfirm: true,
  cancelText: '取消',
  confirmText: '确定',
  closeOnClickModal: false,
  closeOnPressEscape: true,
  appendToBody: true,
  destroyOnClose: false,
  trapFocus: true,
  lockScroll: true,
  top: '10vh'
})

defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'confirm'): void
  (e: 'closed'): void
}>()
</script>
