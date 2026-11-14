import { computed } from 'vue'
import type { MenuVO } from '@/shared/types/menu'
import type { BaseTableColumn } from '@/components/base-table/types'

export function useMenuTableColumns(actions: {
  handleEdit: (row: MenuVO) => void
  handleDelete: (row: MenuVO) => void
}) {
  return computed((): BaseTableColumn[] => [
    { key: 'name', label: '菜单名称', minWidth: 300, align: 'left' },
    { key: 'menuType', label: '菜单类型', width: 100, align: 'left', type: 'custom', slot: 'menuType' },
    { key: 'nameEn', label: '英文名称', minWidth: 140, align: 'left' },
    { key: 'icon', label: '图标', width: 100, align: 'left', type: 'custom', slot: 'icon' },
    { key: 'path', label: '路由路径', minWidth: 140, align: 'left' },
    { key: 'component', label: '组件路径', minWidth: 180, align: 'left', showOverflowTooltip: true },
    { key: 'permission', label: '权限标识', minWidth: 160, align: 'left' },
    { key: 'sort', label: '排序', width: 80, align: 'left' },
    { key: 'createTime', label: '创建时间', width: 180, align: 'left', type: 'datetime' },
    { key: 'updateTime', label: '更新时间', width: 180, align: 'left', type: 'datetime' },
    {
      key: 'actions',
      label: '操作',
      width: 140,
      fixed: 'right',
      align: 'right',
      type: 'actions',
      actions: [
        { label: '编辑', permission: 'system:menu:edit', onClick: row => actions.handleEdit(row as MenuVO) },
        { label: '删除', permission: 'system:menu:delete', onClick: row => actions.handleDelete(row as MenuVO) }
      ]
    }
  ])
}
