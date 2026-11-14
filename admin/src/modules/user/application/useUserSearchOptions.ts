import { computed } from 'vue'
import type { SearchFormOption } from '@/components/search-form/types'

export function useUserSearchOptions() {
  return computed<SearchFormOption[]>(() => [
    {
      label: '用户名：',
      key: 'username',
      type: 'input',
      placeholder: '用户名',
      col: 6
    },
    {
      label: '昵称：',
      key: 'nickname',
      type: 'input',
      placeholder: '昵称',
      col: 6
    },
    {
      label: '邮箱：',
      key: 'email',
      type: 'input',
      placeholder: '邮箱',
      col: 6
    },
    {
      label: '状态：',
      key: 'status',
      type: 'radio',
      options: [
        { label: '全部', value: undefined },
        { label: '启用', value: true },
        { label: '停用', value: false }
      ],
      col: 8,
      width: 'auto'
    }
  ])
}
