import { computed } from 'vue'
import type { SearchFormOption } from '@/components/search-form/types'

export function useRoleSearchOptions() {
  return computed<SearchFormOption[]>(() => [
    {
      label: '角色名称：',
      key: 'name',
      type: 'input',
      placeholder: '请输入角色名称',
      col: 6
    }
  ])
}
