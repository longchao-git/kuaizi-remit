import { computed } from 'vue'
import type { SearchFormOption } from '@/components/search-form/types'

export function useDictSearchOptions() {
  return computed<SearchFormOption[]>(() => [
    {
      label: '标签：',
      key: 'label',
      type: 'input',
      placeholder: '请输入标签',
      col: 6
    },
    {
      label: '键值：',
      key: 'value',
      type: 'input',
      placeholder: '请输入键值',
      col: 6
    }
  ])
}
