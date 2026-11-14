import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { getErrorMessage } from '@/utils/error'
import { hasPermission } from '@/utils/permission'
import { resolveMenuDisplayName } from '@/shared/utils/menuTree'
import type { RoleSaveDTO } from '../domain/RoleSaveDTO'
import type { RoleUpdateDTO } from '../domain/RoleUpdateDTO'
import { useRoleService } from '@/shared/factory/role'
import type { MenuVO } from '@/shared/types/menu'

type RoleForm = Omit<RoleSaveDTO, 'menuIds'> & { menuIds: string[] }

export function useRoleEditPage() {
  const route = useRoute()
  const router = useRouter()
  const roleService = useRoleService()

  const formRef = ref<FormInstance>()
  const formData = reactive<RoleForm>({ name: '', remark: '', sort: 0, menuIds: [] })
  const roleId = computed(() => typeof route.query.id === 'string' ? route.query.id.trim() : '')
  const isPermissionMode = computed(() => route.query.mode === 'permissions')
  const isEdit = computed(() => Boolean(roleId.value) && !isPermissionMode.value)
  const canAssignMenu = computed(() => hasPermission('system:role:assignMenu'))
  const showMenuPermissions = computed(() => isPermissionMode.value || canAssignMenu.value)
  const pageTitle = computed(() => (
    isPermissionMode.value ? '分配菜单权限' : isEdit.value ? '编辑角色' : '新增角色'
  ))
  const formRules = computed<FormRules>(() => isPermissionMode.value ? {} : ({
    name: [{ required: true, message: '请输入角色名称', trigger: 'blur' }],
    remark: [{ required: true, message: '请输入角色描述', trigger: 'blur' }]
  }))

  const menuTreeRef = ref<any>()
  const menuTreeData = ref<MenuVO[]>([])
  const submitting = ref(false)
  const filterText = ref('')
  const isAllExpanded = ref(true)
  const isAllChecked = ref(false)

  const mapMenuTree = (list: MenuVO[]): Array<MenuVO & { displayName: string }> => (
    (list || []).map(menu => {
      const children = Array.isArray(menu.children) ? menu.children : []
      return {
        ...menu,
        displayName: resolveMenuDisplayName(menu),
        children: children.length ? mapMenuTree(children) : children
      }
    })
  )

  const menuTreeDisplayData = computed(() => mapMenuTree(menuTreeData.value))

  watch(filterText, value => menuTreeRef.value?.filter(value))

  const filterNode = (value: string, data: MenuVO & { displayName: string }) => (
    !value || data.displayName.toLowerCase().includes(value.toLowerCase())
  )

  const customNodeClass = (data: MenuVO) => data.menuType === 'BUTTON' ? 'is-button-node' : ''

  const getAllNodeKeys = (nodes: MenuVO[]): string[] => {
    const keys: string[] = []
    const stack = [...(nodes || [])]
    while (stack.length) {
      const node = stack.pop()
      if (!node) continue
      if (node.id) keys.push(node.id)
      if (node.children?.length) stack.push(...node.children)
    }
    return keys
  }

  const syncCheckedState = async (menuIds: string[]) => {
    formData.menuIds = [...menuIds]
    await nextTick()
    menuTreeRef.value?.setCheckedKeys(formData.menuIds)
    const allKeys = getAllNodeKeys(menuTreeData.value)
    isAllChecked.value = allKeys.length > 0 && allKeys.length === formData.menuIds.length
  }

  const handleCheckAll = () => {
    const keys = isAllChecked.value ? [] : getAllNodeKeys(menuTreeDisplayData.value)
    menuTreeRef.value?.setCheckedKeys(keys)
    isAllChecked.value = !isAllChecked.value
    formData.menuIds = (menuTreeRef.value?.getCheckedKeys() || []) as string[]
  }

  const handleExpandAll = () => {
    const tree = menuTreeRef.value
    if (!tree) return
    const expanded = !isAllExpanded.value
    const nodesMap = tree.store.nodesMap as Record<string, { expanded: boolean }>
    Object.values(nodesMap).forEach(node => { node.expanded = expanded })
    isAllExpanded.value = expanded
  }

  const loadMenuTree = async () => {
    if (!showMenuPermissions.value) return
    try {
      menuTreeData.value = await roleService.getMenuOptions()
      await nextTick()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '获取菜单权限树失败'))
    }
  }

  const loadRoleData = async () => {
    if (!isEdit.value) return
    try {
      const roleData = await roleService.getRoleById(roleId.value)
      formData.name = roleData.name
      formData.remark = roleData.remark || ''
      formData.sort = roleData.sort
      if (showMenuPermissions.value) {
        await syncCheckedState(await roleService.getRoleMenuIds(roleId.value))
      }
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '获取角色信息失败'))
    }
  }

  const loadRoleMenus = async () => {
    if (!isPermissionMode.value || !roleId.value) return
    try {
      await syncCheckedState(await roleService.getRoleMenuIds(roleId.value))
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '获取角色菜单权限失败'))
    }
  }

  const getDescendantKeys = (node: MenuVO): string[] => {
    const keys: string[] = []
    const stack = [...(node.children || [])]
    while (stack.length) {
      const current = stack.pop()
      if (!current) continue
      if (current.id) keys.push(current.id)
      if (current.children?.length) stack.push(...current.children)
    }
    return keys
  }

  const handleMenuCheck = (data: MenuVO & { id: string }, checked: { checkedKeys: string[] }) => {
    const tree = menuTreeRef.value
    if (!tree) return
    const isChecked = checked.checkedKeys.includes(data.id)
    getDescendantKeys(data).forEach(key => tree.setChecked(key, isChecked, false))
    formData.menuIds = tree.getCheckedKeys() as string[]
    isAllChecked.value = getAllNodeKeys(menuTreeData.value).length === formData.menuIds.length
  }

  const backToList = () => {
    void router.push('/system/role')
  }

  const handleSubmit = async () => {
    if (!formRef.value) return
    if (showMenuPermissions.value) {
      formData.menuIds = (menuTreeRef.value?.getCheckedKeys() || []) as string[]
    }

    const valid = await formRef.value.validate().catch(() => false)
    if (!valid) return

    submitting.value = true
    try {
      if (isPermissionMode.value) {
        if (!roleId.value) throw new Error('角色 ID 不能为空')
        await roleService.updateRoleMenus({ roleId: roleId.value, menuIds: [...formData.menuIds] })
        ElMessage.success('菜单权限分配成功')
      } else {
        const payload: RoleSaveDTO = {
          name: formData.name,
          remark: formData.remark,
          sort: formData.sort
        }
        if (canAssignMenu.value) payload.menuIds = [...formData.menuIds]

        await roleService.saveOrUpdateRole(isEdit.value
          ? ({ ...payload, id: roleId.value } as RoleUpdateDTO)
          : payload)
        ElMessage.success(isEdit.value ? '角色更新成功' : '角色新增成功')
      }
      backToList()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '操作失败'))
    } finally {
      submitting.value = false
    }
  }

  onMounted(async () => {
    if (isPermissionMode.value && !roleId.value) {
      ElMessage.error('缺少角色 ID')
      backToList()
      return
    }
    await loadMenuTree()
    if (isPermissionMode.value) {
      await loadRoleMenus()
    } else {
      await loadRoleData()
    }
  })

  return {
    formRef,
    formData,
    formRules,
    pageTitle,
    isPermissionMode,
    showMenuPermissions,
    menuTreeRef,
    menuTreeDisplayData,
    submitting,
    filterText,
    isAllExpanded,
    isAllChecked,
    filterNode,
    customNodeClass,
    handleCheckAll,
    handleExpandAll,
    handleMenuCheck,
    handleSubmit,
    handleCancel: backToList
  }
}
