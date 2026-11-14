<template>
  <FormDialog
    v-model:visible="dialogVisible"
    title="选择 Element 图标"
    width="760px"
    @confirm="handleConfirm"
  >
    <div class="icon-picker-dialog">
      <el-input
        v-model="keyword"
        placeholder="请输入图标名称搜索"
        clearable
        class="icon-picker-dialog__search"
      />

      <div class="icon-picker-dialog__grid">
        <button
          v-for="iconName in filteredIcons"
          :key="iconName"
          type="button"
          :class="['icon-picker-dialog__item', { 'is-active': iconName === currentValue } ]"
          @click="currentValue = iconName"
        >
          <el-icon class="icon-picker-dialog__preview">
            <component :is="iconName" />
          </el-icon>
          <span class="icon-picker-dialog__name">{{ iconName }}</span>
        </button>
      </div>
    </div>
  </FormDialog>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import FormDialog from '@/components/dialog/FormDialog.vue'

const props = defineProps<{
  visible: boolean
  modelValue?: string
}>()

const emit = defineEmits<{
  (e: 'update:visible', value: boolean): void
  (e: 'update:modelValue', value: string): void
}>()

const keyword = ref('')
const iconNames = Object.keys(ElementPlusIconsVue).sort((a, b) => a.localeCompare(b))

const dialogVisible = computed({
  get: () => props.visible,
  set: (value: boolean) => emit('update:visible', value)
})

const currentValue = computed({
  get: () => props.modelValue ?? '',
  set: (value: string) => emit('update:modelValue', value)
})

const filteredIcons = computed(() => {
  const search = keyword.value.trim().toLowerCase()
  if (!search) return iconNames
  return iconNames.filter(name => name.toLowerCase().includes(search))
})

const handleConfirm = () => {
  dialogVisible.value = false
}
</script>

<style scoped lang="scss">
.icon-picker-dialog {
  .icon-picker-dialog__search {
    margin-bottom: 16px;
  }

  .icon-picker-dialog__grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 12px;
    max-height: 460px;
    overflow-y: auto;
    padding-right: 4px;
  }

  .icon-picker-dialog__item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 10px;
    min-height: 110px;
    padding: 14px 10px;
    border: 1px solid var(--omc-color-border-light);
    border-radius: 8px;
    background: #fff;
    color: var(--omc-color-text);
    cursor: pointer;
    transition: border-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
    -webkit-appearance: none;
    appearance: none;
  }

  .icon-picker-dialog__item:hover {
    border-color: var(--omc-color-primary);
    transform: translateY(-1px);
  }

  .icon-picker-dialog__item.is-active {
    border-color: var(--omc-color-primary);
    box-shadow: 0 0 0 1px var(--omc-color-primary-soft-border);
    background: var(--omc-color-primary-soft);
  }

  .icon-picker-dialog__preview {
    font-size: 24px;
  }

  .icon-picker-dialog__name {
    width: 100%;
    font-size: 12px;
    line-height: 1.4;
    text-align: center;
    word-break: break-word;
  }
}
</style>
