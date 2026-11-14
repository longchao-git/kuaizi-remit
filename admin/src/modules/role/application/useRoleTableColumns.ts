import { computed } from 'vue'
import type { RoleVO } from '../domain/RoleVO'
import type { BaseTableColumn } from '@/components/base-table/types'

export function useRoleTableColumns(
  actions: {
    handleEdit: (row: RoleVO) => void
    handleAssignPermissions: (row: RoleVO) => void
    openClone: (row: RoleVO) => void
    handleDelete: (row: RoleVO) => void
  },
  state: { superAdminName: string }
) {
  const isSuperAdmin = (row: Record<string, unknown>) => (row as unknown as RoleVO).name === state.superAdminName

  return computed((): BaseTableColumn[] => [
    { key: 'name', label: '角色名称', minWidth: 260, align: 'left' },
    { key: 'remark', label: '备注', minWidth: 320, align: 'left', showOverflowTooltip: true },
    { key: 'createTime', label: '创建时间', minWidth: 240, align: 'left', type: 'datetime' },
    {
      key: 'actions',
      label: '操作',
      fixed: 'right',
      minWidth: 300,
      align: 'right',
      type: 'actions',
      emptyText: '---',
      actions: [
        { label: '编辑', permission: 'system:role:edit', onClick: row => actions.handleEdit(row as RoleVO), hidden: isSuperAdmin },
        { label: '分配权限', permission: 'system:role:assignMenu', onClick: row => actions.handleAssignPermissions(row as RoleVO), hidden: isSuperAdmin },
        {
          label: '克隆',
          permission: ['system:role:add', 'system:role:assignMenu'],
          permissionMode: 'all',
          onClick: row => actions.openClone(row as RoleVO),
          hidden: isSuperAdmin
        },
        { label: '删除', permission: 'system:role:delete', onClick: row => actions.handleDelete(row as RoleVO), hidden: isSuperAdmin }
      ]
    }
  ])
}
