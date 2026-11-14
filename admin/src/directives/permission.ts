import type { Directive, DirectiveBinding } from 'vue'
import { hasPermission } from '@/utils/permission'

/**
 * v-permission 指令
 *
 * 用法：
 *   <el-button v-permission="'system:user:add'">新增</el-button>
 *
 * 如果当前用户没有该权限码，按钮会被从 DOM 中移除。
 */
const ORIGINAL_DISPLAY_KEY = 'permissionDisplay'

function applyPermissionVisibility(el: HTMLElement, permission?: string) {
  const visible = !permission || hasPermission(permission)
  if (visible) {
    const originalDisplay = el.dataset[ORIGINAL_DISPLAY_KEY]
    el.style.display = originalDisplay ?? ''
    return
  }

  if (el.dataset[ORIGINAL_DISPLAY_KEY] === undefined) {
    el.dataset[ORIGINAL_DISPLAY_KEY] = el.style.display || ''
  }
  el.style.display = 'none'
}

export const vPermission: Directive<HTMLElement, string> = {
  mounted(el: HTMLElement, binding: DirectiveBinding<string>) {
    applyPermissionVisibility(el, binding.value)
  },
  updated(el: HTMLElement, binding: DirectiveBinding<string>) {
    applyPermissionVisibility(el, binding.value)
  }
}
