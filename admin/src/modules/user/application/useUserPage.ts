import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { getErrorMessage } from '@/utils/error'
import { hasPermission } from '@/utils/permission'
import { useRoleService } from '@/shared/factory/role'
import { useUserService } from '@/shared/factory/user'
import { useUserStore } from '@/stores/user'
import type { UserVO } from '../domain/UserVO'
import type { UserPageQuery } from '../domain/UserPageQuery'
import type { UserSaveDTO } from '../domain/UserSaveDTO'
import type { UserUpdateDTO } from '../domain/UserUpdateDTO'
import type { RoleVO } from '@/modules/role/domain/RoleVO'
import type { UserTypeEnum } from '../domain/UserTypeEnum'
import { getUserTypeLabel } from '../domain/UserVO'

type UserEditForm = Partial<UserSaveDTO & UserUpdateDTO> & {
  password?: string
  roleIds?: string[]
  userType?: UserTypeEnum
}

export function useUserPage() {
  const userService = useUserService()
  const roleService = useRoleService()
  const userStore = useUserStore()

  const currentUserId = computed(() => String(userStore.profile?.id || ''))
  const canAssignRole = computed(() => hasPermission('system:user:assignRole'))
  const loading = ref(false)
  const tableData = ref<UserVO[]>([])
  const total = ref(0)
  const currentPage = ref(1)
  const pageSize = ref(10)

  const queryParams = reactive<UserPageQuery>({
    current: 1,
    size: 10,
    username: '',
    nickname: '',
    email: ''
  })

  const refresh = async () => {
    loading.value = true
    try {
      queryParams.current = currentPage.value
      queryParams.size = pageSize.value
      const res = await userService.getUserPage(queryParams)
      tableData.value = res.records
      total.value = res.total
      currentPage.value = res.current
      pageSize.value = res.size
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '获取用户列表失败'))
    } finally {
      loading.value = false
    }
  }

  const reset = async () => {
    queryParams.username = ''
    queryParams.nickname = ''
    queryParams.email = ''
    queryParams.status = undefined
    queryParams.userType = undefined
    currentPage.value = 1
    await refresh()
  }

  const handleSizeChange = async (val: number) => {
    pageSize.value = val
    currentPage.value = 1
    await refresh()
  }

  const handleCurrentChange = async (val: number) => {
    currentPage.value = val
    await refresh()
  }

  const roleOptions = ref<RoleVO[]>([])
  const loadRoles = async () => {
    try {
      roleOptions.value = await roleService.getRoleOptions()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '获取角色列表失败'))
    }
  }

  const editDialogVisible = ref(false)
  const editMode = ref<'add' | 'edit'>('add')
  const editFormRef = ref<FormInstance>()
  const saving = ref(false)
  const editForm = reactive<UserEditForm>({
    id: '',
    username: '',
    password: '',
    nickname: '',
    email: '',
    userType: 'NORMAL' as UserTypeEnum,
    roleIds: []
  })

  const editRules = computed<FormRules>(() => ({
    username: [{ required: true, message: '请输入用户名', trigger: 'blur' }],
    password: editMode.value === 'add'
      ? [
          { required: true, message: '请输入密码', trigger: 'blur' },
          { min: 8, max: 20, message: '密码长度必须为 8-20 位', trigger: 'blur' }
        ]
      : [],
    roleIds: editMode.value === 'add' && canAssignRole.value
      ? [{ required: true, message: '请选择角色', trigger: 'change' }]
      : []
  }))

  const openAdd = () => {
    editMode.value = 'add'
    editForm.id = ''
    editForm.username = ''
    editForm.password = ''
    editForm.nickname = ''
    editForm.email = ''
    editForm.userType = 'NORMAL'
    editForm.roleIds = []
    editDialogVisible.value = true
  }

  const openEdit = (row: UserVO) => {
    editMode.value = 'edit'
    editForm.id = row.id
    editForm.username = row.username
    editForm.password = ''
    editForm.nickname = row.nickname
    editForm.email = row.email
    editForm.userType = row.userType
    editForm.roleIds = []
    editDialogVisible.value = true
  }

  const submitEdit = async () => {
    if (!editFormRef.value) return
    const valid = await editFormRef.value.validate().catch(() => false)
    if (!valid) return
    saving.value = true
    try {
      if (editMode.value === 'add') {
        const payload: UserSaveDTO = {
          username: String(editForm.username || ''),
          password: String(editForm.password || ''),
          nickname: editForm.nickname ? String(editForm.nickname) : undefined,
          email: editForm.email ? String(editForm.email) : undefined
        }
        if (canAssignRole.value) payload.roleIds = editForm.roleIds || []
        await userService.save(payload)
        ElMessage.success('用户新增成功')
      } else {
        const payload: UserUpdateDTO = {
          id: String(editForm.id || ''),
          nickname: editForm.nickname ? String(editForm.nickname) : undefined,
          email: editForm.email ? String(editForm.email) : undefined
        }
        await userService.update(payload)
        ElMessage.success('用户修改成功')
      }
      editDialogVisible.value = false
      await refresh()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '操作失败'))
    } finally {
      saving.value = false
    }
  }

  const resetPassword = async (row: UserVO) => {
    if (!row?.id || String(row.id) === currentUserId.value || String(row.userType || '') === 'ADMIN') return
    try {
      const ok = await ElMessageBox.confirm(
        `确认重置用户 ${row.username} 的密码？系统将按后端规则生成新密码。`,
        '提示',
        { confirmButtonText: '确定', cancelButtonText: '取消', type: 'warning' }
      ).catch(() => false)
      if (!ok) return
      await userService.resetPassword(String(row.id))
      ElMessage.success('密码已重置')
      await refresh()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '重置密码失败'))
    }
  }

  const toggleStatus = async (row: UserVO) => {
    try {
      if (!row.id) throw new Error('用户 ID 不能为空')
      if (row.status) {
        await userService.disable(row.id)
        ElMessage.success('已停用')
      } else {
        await userService.enable(row.id)
        ElMessage.success('已启用')
      }
      await refresh()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '操作失败'))
    }
  }

  const doDelete = (row: UserVO) => {
    ElMessageBox.confirm('确认删除该用户吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }).then(async () => {
      try {
        await userService.delete(row.id)
        ElMessage.success('删除成功')
        await refresh()
      } catch (error) {
        ElMessage.error(getErrorMessage(error, '删除失败'))
      }
    })
  }

  const roleDialogVisible = ref(false)
  const roleDialogUser = ref<UserVO | null>(null)
  const roleFormRef = ref<FormInstance>()
  const roleForm = reactive<{ roleIds: string[] }>({ roleIds: [] })
  const roleRules: FormRules = {
    roleIds: [{ required: true, message: '请选择角色', trigger: 'change' }]
  }
  const savingRoles = ref(false)

  const openRoles = (row: UserVO) => {
    if (!canAssignRole.value) return
    roleDialogUser.value = row
    roleForm.roleIds = row.roleIds ? [...row.roleIds] : []
    roleDialogVisible.value = true
    roleFormRef.value?.clearValidate()
  }

  const submitRoles = async () => {
    if (!roleDialogUser.value || !roleFormRef.value) return
    const valid = await roleFormRef.value.validate().catch(() => false)
    if (!valid) return
    savingRoles.value = true
    try {
      await userService.updateRoles({ userId: roleDialogUser.value.id, roleIds: roleForm.roleIds })
      ElMessage.success('保存成功')
      roleDialogVisible.value = false
      await refresh()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '保存失败'))
    } finally {
      savingRoles.value = false
    }
  }

  onMounted(async () => {
    if (canAssignRole.value) await loadRoles()
    await refresh()
  })

  return {
    getUserTypeLabel,
    currentUserId,
    canAssignRole,
    loading,
    tableData,
    total,
    currentPage,
    pageSize,
    queryParams,
    refresh,
    reset,
    handleSizeChange,
    handleCurrentChange,
    roleOptions,
    editDialogVisible,
    editMode,
    editFormRef,
    saving,
    editForm,
    editRules,
    openAdd,
    openEdit,
    submitEdit,
    resetPassword,
    toggleStatus,
    doDelete,
    roleDialogVisible,
    roleDialogUser,
    roleFormRef,
    roleForm,
    roleRules,
    savingRoles,
    openRoles,
    submitRoles
  }
}
