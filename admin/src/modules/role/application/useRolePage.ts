import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import type { RoleVO } from '../domain/RoleVO'
import { getErrorMessage } from '@/utils/error'
import { useRoleService } from '@/shared/factory/role'

const ROLE_LIST_STATE_KEY = 'admin-standard:role-list-state'

export function useRolePage() {
  const router = useRouter()
  const roleService = useRoleService()
  const superAdminName = '管理员'

  const queryParams = reactive({ current: 1, size: 10, name: '' })
  const loading = ref(false)
  const tableData = ref<RoleVO[]>([])
  const total = ref(0)
  const currentPage = ref(1)
  const pageSize = ref(10)

  const cloneDialogVisible = ref(false)
  const cloning = ref(false)
  const cloneFormRef = ref<FormInstance>()
  const cloneSourceRole = ref<RoleVO | null>(null)
  const cloneForm = reactive<{ name: string }>({ name: '' })
  const cloneRules: FormRules = {
    name: [{ required: true, message: '请输入克隆后的角色名称', trigger: 'blur' }]
  }

  const saveListState = () => {
    sessionStorage.setItem(ROLE_LIST_STATE_KEY, JSON.stringify({
      currentPage: currentPage.value,
      pageSize: pageSize.value,
      queryParams: { ...queryParams }
    }))
  }

  const restoreListState = () => {
    const raw = sessionStorage.getItem(ROLE_LIST_STATE_KEY)
    if (!raw) return
    try {
      const state = JSON.parse(raw) as {
        currentPage?: number
        pageSize?: number
        queryParams?: { name?: string }
      }
      currentPage.value = Number(state.currentPage || 1)
      pageSize.value = Number(state.pageSize || 10)
      queryParams.current = currentPage.value
      queryParams.size = pageSize.value
      queryParams.name = String(state.queryParams?.name || '')
    } catch {
      sessionStorage.removeItem(ROLE_LIST_STATE_KEY)
    }
  }

  const getList = async () => {
    loading.value = true
    try {
      queryParams.current = currentPage.value
      queryParams.size = pageSize.value
      const response = await roleService.getRolePage(queryParams)
      tableData.value = response.records
      total.value = response.total
      currentPage.value = response.current
      pageSize.value = response.size
      saveListState()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '获取角色列表失败'))
    } finally {
      loading.value = false
    }
  }

  const handleSearch = () => {
    queryParams.current = 1
    currentPage.value = 1
    void getList()
  }

  const resetSearch = () => {
    queryParams.name = ''
    queryParams.current = 1
    currentPage.value = 1
    void getList()
  }

  const handleAdd = () => {
    saveListState()
    void router.push('/system/role/edit')
  }

  const handleEdit = (row: RoleVO) => {
    saveListState()
    void router.push({ path: '/system/role/edit', query: { id: row.id } })
  }

  const handleAssignPermissions = (row: RoleVO) => {
    saveListState()
    void router.push({
      path: '/system/role/edit',
      query: { id: row.id, mode: 'permissions' }
    })
  }

  const openClone = (row: RoleVO) => {
    cloneSourceRole.value = row
    cloneForm.name = `${row.name}-副本`
    cloneDialogVisible.value = true
  }

  const submitClone = async () => {
    const form = cloneFormRef.value
    if (!form) return
    await form.validate()
    if (!cloneSourceRole.value?.id) return

    cloning.value = true
    try {
      await roleService.cloneRole({ sourceRoleId: cloneSourceRole.value.id, name: cloneForm.name })
      ElMessage.success('角色克隆成功')
      cloneDialogVisible.value = false
      await getList()
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '角色克隆失败'))
    } finally {
      cloning.value = false
    }
  }

  const handleDelete = (row: RoleVO) => {
    ElMessageBox.confirm('确认删除该角色吗？', '提示', {
      confirmButtonText: '确定',
      cancelButtonText: '取消',
      type: 'warning'
    }).then(async () => {
      try {
        await roleService.deleteRole(row.id)
        ElMessage.success('删除成功')
        await getList()
      } catch (error) {
        ElMessage.error(getErrorMessage(error, '删除失败'))
      }
    })
  }

  const handleSizeChange = (val: number) => {
    pageSize.value = val
    currentPage.value = 1
    queryParams.size = val
    queryParams.current = 1
    void getList()
  }

  const handleCurrentChange = (val: number) => {
    currentPage.value = val
    queryParams.current = val
    void getList()
  }

  onMounted(() => {
    restoreListState()
    void getList()
  })

  return {
    superAdminName,
    queryParams,
    loading,
    tableData,
    total,
    currentPage,
    pageSize,
    cloneDialogVisible,
    cloning,
    cloneFormRef,
    cloneSourceRole,
    cloneForm,
    cloneRules,
    handleSearch,
    resetSearch,
    handleAdd,
    handleEdit,
    handleAssignPermissions,
    openClone,
    submitClone,
    handleDelete,
    handleSizeChange,
    handleCurrentChange
  }
}
