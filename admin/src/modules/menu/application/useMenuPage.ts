import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { CascaderOption, CascaderProps, FormInstance, FormRules } from 'element-plus'
import * as ElementPlusIconsVue from '@element-plus/icons-vue'
import type { MenuVO } from '@/shared/types/menu'
import { sanitizeMenuRemark } from '@/shared/utils/menuTree'
import type { MenuSaveDTO } from '../domain/MenuSaveDTO'
import type { MenuUpdateDTO } from '../domain/MenuUpdateDTO'
import { getErrorMessage } from '@/utils/error'
import { useMenuService } from '@/shared/factory/menu'

type MenuDialogType = 'add' | 'edit'
type MenuFormData = MenuSaveDTO & { id?: string }
type MenuCascaderOption = CascaderOption & { value: string; label: string; children?: MenuCascaderOption[] }

const elementIconNames = new Set(Object.keys(ElementPlusIconsVue))

export function useMenuPage() {
  const menuService = useMenuService()

  const loading = ref(false)
  const menuList = ref<MenuVO[]>([])
  const dialogVisible = ref(false)
  const dialogType = ref<MenuDialogType>('add')
  const formRef = ref<FormInstance>()
  const formData = reactive<MenuFormData>({
    name: '',
    nameEn: '',
    menuType: 'MENU',
    path: '',
    component: '',
    icon: '',
    permission: '',
    visible: true,
    remark: '',
    sort: 0,
    parentId: undefined
  })
  const submitting = ref(false)
  const iconDialogVisible = ref(false)

  const buildMenuPathMap = (nodes: MenuVO[], parents: string[] = []) => {
    const result = new Map<string, string[]>()

    nodes.forEach((node) => {
      const currentId = String(node.id || '')
      if (!currentId) return

      const currentPath = [...parents, currentId]
      result.set(currentId, currentPath)

      const children = Array.isArray(node.children) ? node.children : []
      buildMenuPathMap(children, currentPath).forEach((value, key) => result.set(key, value))
    })

    return result
  }

  const menuPathMap = computed(() => buildMenuPathMap(menuList.value))

  const parentMenuOptions = computed<MenuCascaderOption[]>(() => {
    const walk = (nodes: MenuVO[]): MenuCascaderOption[] => {
      return nodes
        .filter(node => node.menuType === 'MENU' && node.id && node.id !== formData.id)
        .map((node) => {
          const children = walk(Array.isArray(node.children) ? node.children : [])
          return {
            value: String(node.id),
            label: String(node.name || node.id),
            ...(children.length > 0 ? { children } : {})
          }
        })
    }

    return walk(menuList.value)
  })

  const parentMenuPath = computed<string[]>({
    get: () => {
      const currentId = String(formData.parentId || '').trim()
      return currentId ? menuPathMap.value.get(currentId) || [] : []
    },
    set: value => {
      const path = Array.isArray(value) ? value : []
      formData.parentId = path.length > 0 ? path[path.length - 1] : undefined
    }
  })

  const parentMenuCascaderProps: CascaderProps = {
    checkStrictly: true,
    emitPath: true,
    value: 'value',
    label: 'label',
    children: 'children'
  }

  const isElementIcon = (icon?: string) => {
    const value = String(icon || '').trim()
    return Boolean(value) && elementIconNames.has(value)
  }

  const validatePermissionUnique = (
    _rule: unknown,
    value: unknown,
    callback: (error?: Error) => void
  ) => {
    const valueStr = String(value ?? '').trim()
    if (!valueStr) return callback()
    const currentId = dialogType.value === 'edit' ? formData.id : null
    const isDuplicate = menuService.isPermissionDuplicate(menuList.value, valueStr, currentId)
    isDuplicate ? callback(new Error('权限标识不能重复')) : callback()
  }

  const validateParentId = (
    _rule: unknown,
    _value: unknown,
    callback: (error?: Error) => void
  ) => {
    if (formData.menuType === 'BUTTON' && !String(formData.parentId || '').trim()) {
      callback(new Error('请选择上级菜单'))
      return
    }
    callback()
  }

  const validatePath = (
    _rule: unknown,
    value: unknown,
    callback: (error?: Error) => void
  ) => {
    if (formData.menuType === 'MENU' && !String(value || '').trim()) {
      callback(new Error('请输入路由路径'))
      return
    }
    callback()
  }

  const validateComponent = (
    _rule: unknown,
    value: unknown,
    callback: (error?: Error) => void
  ) => {
    if (formData.menuType === 'MENU' && !String(value || '').trim()) {
      callback(new Error('请输入组件路径'))
      return
    }
    callback()
  }

  const formRules = computed<FormRules>(() => ({
    name: [{ required: true, message: '请输入菜单名称', trigger: 'blur' }],
    parentId: [{ validator: validateParentId, trigger: 'change' }],
    path: [{ validator: validatePath, trigger: 'blur' }],
    component: [{ validator: validateComponent, trigger: 'blur' }],
    sort: [{ required: true, message: '请输入排序号', trigger: 'blur' }],
    permission: [
      { pattern: /^[A-Za-z][A-Za-z0-9:_-]*$/, message: '权限标识需以字母开头，支持字母、数字、冒号、下划线、中划线', trigger: 'blur' },
      { validator: validatePermissionUnique, trigger: 'blur' }
    ]
  }))

  const clearIcon = () => {
    formData.icon = ''
  }

  const getList = async () => {
    loading.value = true
    try {
      const data = await menuService.getMenuTree()
      menuList.value = data
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '获取菜单列表失败'))
    } finally {
      loading.value = false
    }
  }

  const handleAdd = () => {
    dialogType.value = 'add'
    resetForm()
    dialogVisible.value = true
  }

  const handleEdit = (row: MenuVO) => {
    dialogType.value = 'edit'
    resetForm()
    formData.id = row.id
    formData.name = row.name || ''
    formData.nameEn = row.nameEn || ''
    formData.menuType = row.menuType || 'MENU'
    formData.path = row.path || ''
    formData.component = row.component || ''
    formData.icon = row.icon || ''
    formData.permission = row.permission || ''
    formData.visible = row.visible !== false
    formData.remark = sanitizeMenuRemark(row.remark) || ''
    formData.sort = Number(row.sort ?? 0) || 0
    formData.parentId = row.parentId
    dialogVisible.value = true
  }

  const handleDelete = (row: MenuVO) => {
    ElMessageBox.confirm('确认删除该菜单吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }).then(async () => {
      try {
        if (row.id) {
          await menuService.deleteMenu(row.id)
        } else {
          throw new Error('菜单 ID 不能为空')
        }
        ElMessage.success('删除成功')
        getList()
      } catch (error) {
        ElMessage.error(getErrorMessage(error, '删除失败'))
      }
    })
  }

  const handleSubmit = async () => {
    if (!formRef.value) return

    await formRef.value.validate(async (valid: boolean) => {
      if (!valid) return
      submitting.value = true
      try {
        if (dialogType.value === 'add') {
          const payload: MenuSaveDTO = {
            name: formData.name,
            nameEn: formData.name ? formData.nameEn : undefined,
            menuType: formData.menuType,
            path: formData.path || undefined,
            component: formData.component || undefined,
            icon: formData.icon || undefined,
            permission: formData.permission || undefined,
            visible: formData.visible !== false,
            remark: formData.remark || undefined,
            sort: Number(formData.sort ?? 0) || 0,
            parentId: formData.parentId || undefined
          }
          await menuService.saveMenu(payload)
          ElMessage.success('菜单新增成功')
        } else {
          const payload: MenuUpdateDTO = {
            id: String(formData.id || ''),
            name: formData.name,
            nameEn: formData.name ? formData.nameEn : undefined,
            menuType: formData.menuType,
            path: formData.path || undefined,
            component: formData.component || undefined,
            icon: formData.icon || undefined,
            permission: formData.permission || undefined,
            visible: formData.visible !== false,
            remark: formData.remark || undefined,
            sort: Number(formData.sort ?? 0) || 0,
            parentId: formData.parentId || undefined
          }
          await menuService.updateMenu(payload)
          ElMessage.success('菜单修改成功')
        }
        dialogVisible.value = false
        getList()
      } catch (error) {
        ElMessage.error(getErrorMessage(error, '操作失败'))
      } finally {
        submitting.value = false
      }
    })
  }

  const resetForm = () => {
    if (formRef.value) {
      formRef.value.resetFields()
    }
    iconDialogVisible.value = false
    formData.name = ''
    formData.nameEn = ''
    formData.menuType = 'MENU'
    formData.path = ''
    formData.component = ''
    formData.icon = ''
    formData.permission = ''
    formData.visible = true
    formData.remark = ''
    formData.sort = 0
    formData.parentId = undefined
    delete formData.id
  }

  onMounted(() => {
    getList()
  })

  return {
    loading,
    menuList,
    dialogVisible,
    dialogType,
    formRef,
    formData,
    parentMenuOptions,
    parentMenuPath,
    parentMenuCascaderProps,
    formRules,
    submitting,
    iconDialogVisible,
    isElementIcon,
    clearIcon,
    handleAdd,
    handleEdit,
    handleDelete,
    handleSubmit
  }
}
