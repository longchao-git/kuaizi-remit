import { computed } from 'vue'
import type { UserVO } from '../domain/UserVO'
import type { BaseTableColumn } from '@/components/base-table/types'
import type { UserTypeEnum } from '../domain/UserTypeEnum'

export function useUserTableColumns(
  getUserTypeLabel: (value?: UserTypeEnum) => string,
  actions: {
    openEdit: (row: UserVO) => void
    openRoles: (row: UserVO) => void
    toggleStatus: (row: UserVO) => void
    resetPassword: (row: UserVO) => void
    doDelete: (row: UserVO) => void
  },
  state: {
    currentUserId: () => string
  }
) {
  return computed((): BaseTableColumn[] => [
    { key: 'username', label: '用户名', minWidth: 180, align: 'left' },
    { key: 'nickname', label: '昵称', minWidth: 160, align: 'left', showOverflowTooltip: true },
    { key: 'email', label: '邮箱', minWidth: 220, align: 'left', showOverflowTooltip: true },
    {
      key: 'userType',
      label: '用户类型',
      minWidth: 120,
      align: 'left',
      formatter: row => getUserTypeLabel((row as UserVO).userType)
    },
    { key: 'roleName', label: '角色', minWidth: 200, align: 'left', showOverflowTooltip: true },
    {
      key: 'status',
      label: '状态',
      minWidth: 100,
      align: 'left',
      type: 'tag',
      tagMap: {
        true: { label: '启用', type: 'success' },
        false: { label: '停用', type: 'info' }
      }
    },
    { key: 'createTime', label: '创建时间', minWidth: 180, align: 'left', type: 'datetime' },
    {
      key: 'actions',
      label: '操作',
      fixed: 'right',
      width: 340,
      align: 'right',
      type: 'actions',
      actions: [
        { label: '编辑', permission: 'system:user:edit', onClick: row => actions.openEdit(row as UserVO) },
        { label: '分配角色', permission: 'system:user:assignRole', onClick: row => actions.openRoles(row as UserVO) },
        {
          label: '停用',
          permission: 'system:user:status',
          onClick: row => actions.toggleStatus(row as UserVO),
          hidden: row => !(row as UserVO).status
        },
        {
          label: '启用',
          permission: 'system:user:status',
          onClick: row => actions.toggleStatus(row as UserVO),
          hidden: row => Boolean((row as UserVO).status)
        },
        {
          label: '重置密码',
          permission: 'system:user:resetPassword',
          onClick: row => actions.resetPassword(row as UserVO),
          hidden: row => String((row as UserVO).id) === state.currentUserId() || String((row as UserVO).userType || '') === 'ADMIN'
        },
        { label: '删除', permission: 'system:user:delete', onClick: row => actions.doDelete(row as UserVO) }
      ]
    }
  ])
}
