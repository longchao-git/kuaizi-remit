import { computed } from 'vue'
import type { DictVO } from '../domain/DictVO'
import type { BaseTableColumn } from '@/components/base-table/types'

export function useDictTableColumns(
  actions: {
    openEdit: (row: DictVO) => void
    toggleEnable: (row: DictVO) => void
    doDelete: (row: DictVO) => void
  },
  state: {
    selectedCategoryEnabled: () => boolean
  }
) {
  return computed((): BaseTableColumn[] => [
    { key: 'label', label: '标签', minWidth: 160, align: 'left', showOverflowTooltip: true },
    { key: 'value', label: '键值', minWidth: 160, align: 'left', showOverflowTooltip: true },
    { key: 'sort', label: '排序', width: 90, align: 'left' },
    {
      key: 'status',
      label: '状态',
      width: 110,
      align: 'left',
      type: 'tag',
      tagMap: {
        true: { label: '启用', type: 'success' },
        false: { label: '停用', type: 'info' }
      }
    },
    { key: 'remark', label: '备注', minWidth: 180, align: 'left', showOverflowTooltip: true },
    {
      key: 'actions',
      label: '操作',
      fixed: 'right',
      width: 260,
      align: 'right',
      type: 'actions',
      actions: [
        { label: '编辑', permission: 'system:dict:edit', onClick: row => actions.openEdit(row as DictVO), disabled: () => !state.selectedCategoryEnabled() },
        {
          label: '停用',
          permission: 'system:dict:status',
          onClick: row => actions.toggleEnable(row as DictVO),
          hidden: row => !(row as DictVO).status
        },
        {
          label: '启用',
          permission: 'system:dict:status',
          onClick: row => actions.toggleEnable(row as DictVO),
          hidden: row => Boolean((row as DictVO).status)
        },
        { label: '删除', permission: 'system:dict:delete', onClick: row => actions.doDelete(row as DictVO) }
      ]
    }
  ])
}
