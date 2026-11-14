<template>
  <el-table
    ref="tableRef"
    v-bind="$attrs"
    :data="displayData"
    :row-key="rowKey"
    :header-cell-style="mergedHeaderStyle"
    @selection-change="handleSelectionChange"
  >
    <el-table-column
      v-if="multiple"
      type="selection"
      :width="selectionColumnWidth"
      :reserve-selection="reserveSelection"
    />

    <template v-if="columns.length">
      <el-table-column
        v-for="column in columns"
        :key="column.key"
        :prop="column.prop || column.key"
        :label="column.label"
        :width="column.width"
        :min-width="column.minWidth"
        :fixed="column.fixed"
        :align="column.align"
        :header-align="column.headerAlign || column.align"
        :show-overflow-tooltip="column.showOverflowTooltip"
      >
        <template v-if="column.headerSlot || column.headerTips" #header="scope">
          <slot :name="column.headerSlot || `header-${column.key}`" v-bind="{ ...scope, column }">
            {{ column.label }}
          </slot>
        </template>

        <template #default="scope">
          <template v-if="column.type === 'custom'">
            <slot :name="column.slot || column.key" v-bind="scope" />
          </template>

          <template v-else-if="column.type === 'actions'">
            <div class="base-table__actions" :class="`base-table__actions--${column.align || 'right'}`">
              <template v-if="resolveVisibleActions(column, scope.row, scope.$index).length">
                <el-button
                  v-for="action in resolvePrimaryActions(column, scope.row, scope.$index)"
                  :key="action.key || action.label"
                  link
                  :type="action.type || 'primary'"
                  size="small"
                  :loading="isActionRunning(action, scope.row, scope.$index)"
                  :disabled="resolveActionDisabled(action, scope.row, scope.$index)"
                  @click="runAction(action, scope.row, scope.$index)"
                >
                  {{ action.label }}
                </el-button>

                <el-dropdown
                  v-if="resolveMoreActions(column, scope.row, scope.$index).length"
                  trigger="click"
                  @command="handleDropdownCommand($event, scope.row, scope.$index)"
                >
                  <el-button link type="primary" size="small">
                    {{ column.moreActionText || '更多' }}
                    <el-icon class="base-table__more-icon"><ArrowDown /></el-icon>
                  </el-button>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item
                        v-for="action in resolveMoreActions(column, scope.row, scope.$index)"
                        :key="action.key || action.label"
                        :command="action"
                        :disabled="resolveActionDisabled(action, scope.row, scope.$index) || isActionRunning(action, scope.row, scope.$index)"
                      >
                        {{ action.label }}
                      </el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
              </template>
              <span v-else>{{ resolveEmptyText(column) }}</span>
            </div>
          </template>

          <template v-else-if="column.type === 'tag'">
            <el-tag
              v-if="resolveTagMeta(scope.row, column, scope.$index).label"
              :type="resolveTagMeta(scope.row, column, scope.$index).type"
            >
              {{ resolveTagMeta(scope.row, column, scope.$index).label }}
            </el-tag>
            <span v-else>{{ resolveEmptyText(column) }}</span>
          </template>

          <template v-else-if="column.type === 'datetime'">
            {{ resolveDisplayValue(scope.row, column, scope.$index, true) }}
          </template>

          <template v-else-if="column.type === 'image'">
            <el-image
              v-if="resolveRawValue(scope.row, column, scope.$index)"
              :src="String(resolveRawValue(scope.row, column, scope.$index))"
              :preview-src-list="column.imagePreview === false ? [] : [String(resolveRawValue(scope.row, column, scope.$index))]"
              :preview-teleported="column.imagePreview !== false"
              :fit="column.imageFit || 'cover'"
              :style="resolveImageStyle(column)"
            />
            <span v-else>{{ resolveEmptyText(column) }}</span>
          </template>

          <template v-else>
            {{ resolveDisplayValue(scope.row, column, scope.$index) }}
          </template>
        </template>
      </el-table-column>
    </template>

    <slot v-else />
  </el-table>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import type { TableInstance } from 'element-plus'
import { ElMessage } from 'element-plus'
import { formatAdminDateTime, formatDate } from '@/utils/date'
import { getErrorMessage } from '@/utils/error'
import { hasAnyPermission, hasPermission } from '@/utils/permission'
import type { BaseTableAction, BaseTableColumn, BaseTableProps, BaseTableTagMapItem } from './types'

defineOptions({ inheritAttrs: false })

const props = withDefaults(defineProps<BaseTableProps>(), {
  data: () => [],
  columns: () => [],
  rowKey: 'id',
  headerCellStyle: undefined,
  emptyText: '-',
  multiple: false,
  reserveSelection: true,
  selectionColumnWidth: 48,
  selectedRowKeys: () => [],
  selectionKey: 'id',
  rowDraggable: false
})

const emit = defineEmits<{
  (e: 'selection-change', value: Record<string, any>[]): void
  (e: 'row-drop', value: Record<string, any>[]): void
}>()

const tableRef = ref<TableInstance>()
const displayData = ref<Record<string, any>[]>([])
const runningActionKeys = ref(new Set<string>())
let dragStartIndex = -1
let cleanupDragListeners: Array<() => void> = []

const defaultHeaderStyle: BaseTableProps['headerCellStyle'] = {
  background: '#F7F8FA',
  color: '#000',
  height: '54px',
  fontSize: '14px',
  fontWeight: 400
}

const mergedHeaderStyle = computed(() => (
  props.headerCellStyle ? { ...defaultHeaderStyle, ...props.headerCellStyle } : defaultHeaderStyle
))

watch(
  () => props.data,
  value => {
    displayData.value = [...value]
  },
  { immediate: true, deep: true }
)

watch(
  [displayData, () => props.selectedRowKeys, () => props.multiple, () => props.rowDraggable],
  async () => {
    await nextTick()
    syncSelection()
    await bindRowDrag()
  },
  { deep: true }
)

const getColumnValue = (row: Record<string, any>, column: BaseTableColumn) => row?.[column.prop || column.key]

const resolveRawValue = (row: Record<string, any>, column: BaseTableColumn, index: number) => (
  column.formatter ? column.formatter(row, index) : getColumnValue(row, column)
)

const isEmptyValue = (value: unknown) => value === undefined || value === null || value === ''
const resolveEmptyText = (column: BaseTableColumn) => column.emptyText || props.emptyText

const resolveDisplayValue = (row: Record<string, any>, column: BaseTableColumn, index: number, forceDateTime = false) => {
  const rawValue = resolveRawValue(row, column, index)
  if (isEmptyValue(rawValue)) return resolveEmptyText(column)
  if (forceDateTime || column.type === 'datetime') {
    const formatted = !column.datetimeFormat || column.datetimeFormat === 'YYYY-MM-DD HH:mm'
      ? formatAdminDateTime(rawValue as string | Date | number)
      : formatDate(rawValue as string | Date | number, column.datetimeFormat)
    return formatted || resolveEmptyText(column)
  }
  return String(rawValue)
}

const resolveTagMeta = (row: Record<string, any>, column: BaseTableColumn, index: number): BaseTableTagMapItem => {
  const rawValue = resolveRawValue(row, column, index)
  if (isEmptyValue(rawValue)) return { label: '' }

  const matched = column.tagMap?.[String(rawValue)]
  if (typeof matched === 'string') {
    return {
      label: matched,
      type: typeof column.tagType === 'function' ? column.tagType(rawValue, row, index) : column.tagType
    }
  }
  if (matched) return matched

  return {
    label: column.tagLabel ? column.tagLabel(rawValue, row, index) : String(rawValue),
    type: typeof column.tagType === 'function' ? column.tagType(rawValue, row, index) : column.tagType
  }
}

const hasActionPermission = (action: BaseTableAction) => {
  if (!action.permission) return true
  const permissions = Array.isArray(action.permission) ? action.permission : [action.permission]
  return action.permissionMode === 'all'
    ? permissions.every(permission => hasPermission(permission))
    : hasAnyPermission(permissions)
}

const resolveVisibleActions = (column: BaseTableColumn, row: Record<string, any>, index: number) => (
  (column.actions || []).filter(action => {
    const hidden = typeof action.hidden === 'function' ? action.hidden(row, index) : Boolean(action.hidden)
    return !hidden && hasActionPermission(action)
  })
)

const resolvePrimaryActions = (column: BaseTableColumn, row: Record<string, any>, index: number) => {
  const count = Math.max(0, column.primaryActionCount ?? 2)
  return resolveVisibleActions(column, row, index).slice(0, count)
}

const resolveMoreActions = (column: BaseTableColumn, row: Record<string, any>, index: number) => {
  const count = Math.max(0, column.primaryActionCount ?? 2)
  return resolveVisibleActions(column, row, index).slice(count)
}

const resolveActionDisabled = (action: BaseTableAction, row: Record<string, any>, index: number) => (
  typeof action.disabled === 'function' ? action.disabled(row, index) : Boolean(action.disabled)
)

const resolveRowIdentity = (row: Record<string, any>, index: number) => {
  if (typeof props.rowKey === 'function') return props.rowKey(row)
  return String(row?.[props.rowKey] ?? index)
}

const resolveActionKey = (action: BaseTableAction, row: Record<string, any>, index: number) => (
  `${resolveRowIdentity(row, index)}:${action.key || action.label}`
)

const isActionRunning = (action: BaseTableAction, row: Record<string, any>, index: number) => (
  runningActionKeys.value.has(resolveActionKey(action, row, index))
)

const runAction = async (action: BaseTableAction, row: Record<string, any>, index: number) => {
  if (resolveActionDisabled(action, row, index)) return
  const key = resolveActionKey(action, row, index)
  if (runningActionKeys.value.has(key)) return

  runningActionKeys.value.add(key)
  try {
    await action.onClick(row, index)
  } catch (error) {
    ElMessage.error(getErrorMessage(error, `${action.label}失败`))
  } finally {
    runningActionKeys.value.delete(key)
  }
}

const handleDropdownCommand = (action: BaseTableAction, row: Record<string, any>, index: number) => {
  void runAction(action, row, index)
}

const resolveImageStyle = (column: BaseTableColumn) => ({
  width: column.imageWidth || '40px',
  height: column.imageHeight || '40px'
})

const handleSelectionChange = (value: Record<string, any>[]) => emit('selection-change', value)

const syncSelection = () => {
  if (!props.multiple || !tableRef.value) return
  tableRef.value.clearSelection()
  if (!props.selectedRowKeys.length) return

  const selectedKeySet = new Set(props.selectedRowKeys.map(String))
  displayData.value.forEach(row => {
    if (selectedKeySet.has(String(row?.[props.selectionKey]))) {
      tableRef.value?.toggleRowSelection(row, true)
    }
  })
}

const clearRowDragListeners = () => {
  cleanupDragListeners.forEach(cleanup => cleanup())
  cleanupDragListeners = []
}

const bindRowDrag = async () => {
  clearRowDragListeners()
  if (!props.rowDraggable) return
  await nextTick()

  const tableEl = tableRef.value?.$el as HTMLElement | undefined
  const rows = tableEl?.querySelectorAll('.el-table__body-wrapper tbody tr')
  if (!rows?.length) return

  rows.forEach((rowEl, index) => {
    const element = rowEl as HTMLElement
    element.draggable = true

    const onDragStart = () => {
      dragStartIndex = index
      element.classList.add('base-table__row--dragging')
    }
    const onDragOver = (event: DragEvent) => {
      event.preventDefault()
      element.classList.add('base-table__row--drag-over')
    }
    const onDragLeave = () => element.classList.remove('base-table__row--drag-over')
    const onDrop = (event: DragEvent) => {
      event.preventDefault()
      element.classList.remove('base-table__row--drag-over')
      if (dragStartIndex < 0 || dragStartIndex === index) return

      const next = [...displayData.value]
      const moved = next.splice(dragStartIndex, 1)[0]
      if (!moved) return
      next.splice(index, 0, moved)
      displayData.value = next
      emit('row-drop', next)
      dragStartIndex = -1
    }
    const onDragEnd = () => {
      element.classList.remove('base-table__row--drag-over', 'base-table__row--dragging')
      dragStartIndex = -1
    }

    element.addEventListener('dragstart', onDragStart)
    element.addEventListener('dragover', onDragOver)
    element.addEventListener('dragleave', onDragLeave)
    element.addEventListener('drop', onDrop)
    element.addEventListener('dragend', onDragEnd)
    cleanupDragListeners.push(() => {
      element.removeEventListener('dragstart', onDragStart)
      element.removeEventListener('dragover', onDragOver)
      element.removeEventListener('dragleave', onDragLeave)
      element.removeEventListener('drop', onDrop)
      element.removeEventListener('dragend', onDragEnd)
    })
  })
}

onMounted(() => void bindRowDrag())
onBeforeUnmount(clearRowDragListeners)
</script>

<style scoped lang="scss">
:deep(.el-table__body .el-table__row > td) {
  height: auto;
  padding-top: 0;
  padding-bottom: 0;
}

:deep(.el-table__body .el-table__row > td .cell) {
  min-height: 60px;
  padding: 12px;
  display: flex;
  align-items: center;
  box-sizing: border-box;
}

.base-table__actions {
  display: flex;
  align-items: center;
  gap: 4px;
  width: 100%;

  &--left { justify-content: flex-start; }
  &--center { justify-content: center; }
  &--right { justify-content: flex-end; }
}

.base-table__more-icon {
  margin-left: 2px;
}

:deep(.base-table__row--dragging) { opacity: 0.5; }
:deep(.base-table__row--drag-over) { box-shadow: inset 0 2px 0 var(--omc-color-primary); }
</style>
