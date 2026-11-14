import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { getErrorMessage } from '@/utils/error'
import { useDictService } from '@/shared/factory/dict'
import type { DictCategorySaveDTO } from '../domain/DictCategorySaveDTO'
import type { DictCategoryUpdateDTO } from '../domain/DictCategoryUpdateDTO'
import type { DictCategoryVO } from '../domain/DictCategoryVO'
import type { DictVO } from '../domain/DictVO'
import type { DictSaveDTO } from '../domain/DictSaveDTO'
import type { DictUpdateDTO } from '../domain/DictUpdateDTO'

export function useDictPage() {
  const dictService = useDictService()

  const categoriesLoading = ref(false)
  const itemsLoading = ref(false)
  const categories = ref<DictCategoryVO[]>([])
  const tableData = ref<DictVO[]>([])
  const selectedCategoryId = ref('')

  const filterForm = reactive({
    label: '',
    value: ''
  })

  const selectedCategory = computed(() => categories.value.find(item => item.id === selectedCategoryId.value) || null)
  const selectedCategoryEnabled = computed(() => Boolean(selectedCategory.value?.status))

  const filteredItems = computed(() => {
    const label = String(filterForm.label || '').trim().toLowerCase()
    const value = String(filterForm.value || '').trim().toLowerCase()
    return tableData.value.filter(item => {
      const itemLabel = String(item.label || '').toLowerCase()
      const itemValue = String(item.value || '').toLowerCase()
      return (!label || itemLabel.includes(label)) && (!value || itemValue.includes(value))
    })
  })

  const loadItems = async () => {
    if (!selectedCategoryId.value) {
      tableData.value = []
      return
    }
    itemsLoading.value = true
    try {
      tableData.value = await dictService.getByCategoryId(selectedCategoryId.value)
    } catch (e) {
      ElMessage.error(getErrorMessage(e, '获取字典项失败'))
    } finally {
      itemsLoading.value = false
    }
  }

  const loadCategories = async () => {
    categoriesLoading.value = true
    try {
      categories.value = await dictService.getCategories(false)
      if (!selectedCategoryId.value || !categories.value.some(item => item.id === selectedCategoryId.value)) {
        selectedCategoryId.value = categories.value[0]?.id || ''
      }
      await loadItems()
    } catch (e) {
      ElMessage.error(getErrorMessage(e, '获取字典分类失败'))
    } finally {
      categoriesLoading.value = false
    }
  }

  const selectCategory = async (id: string) => {
    selectedCategoryId.value = id
    await loadItems()
  }

  const refreshCategories = async () => {
    await loadCategories()
  }

  const ensureSelectedCategoryEnabled = () => {
    if (selectedCategoryEnabled.value) {
      return true
    }
    ElMessage.warning('当前分类已停用，不能编辑字典项')
    return false
  }

  const resetFilters = async () => {
    filterForm.label = ''
    filterForm.value = ''
    await loadItems()
  }

  const categoryDialogVisible = ref(false)
  const categoryEditMode = ref<'add' | 'edit'>('add')
  const categoryFormRef = ref<FormInstance>()
  const categorySaving = ref(false)

  const categoryForm = reactive({
    id: '',
    name: '',
    code: '',
    dictType: 'BUSINESS',
    sort: 0,
    remark: ''
  })

  const categoryRules: FormRules = {
    name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
    code: [{ required: true, message: '请输入分类编码', trigger: 'blur' }],
    sort: [{ required: true, message: '请输入排序号', trigger: 'blur' }]
  }

  const openCategoryAdd = () => {
    categoryEditMode.value = 'add'
    Object.assign(categoryForm, {
      id: '',
      name: '',
      code: '',
      dictType: 'BUSINESS',
      sort: 0,
      remark: ''
    })
    categoryDialogVisible.value = true
    categoryFormRef.value?.clearValidate()
  }

  const openCategoryEdit = (row: DictCategoryVO) => {
    categoryEditMode.value = 'edit'
    Object.assign(categoryForm, {
      id: row.id,
      name: row.name,
      code: row.code,
      dictType: row.dictType || 'BUSINESS',
      sort: row.sort || 0,
      remark: row.remark || ''
    })
    categoryDialogVisible.value = true
    categoryFormRef.value?.clearValidate()
  }

  const submitCategoryEdit = async () => {
    if (!categoryFormRef.value) return
    const valid = await categoryFormRef.value.validate().catch(() => false)
    if (!valid) return
    categorySaving.value = true
    try {
      if (categoryEditMode.value === 'add') {
        const dto: DictCategorySaveDTO = {
          code: String(categoryForm.code || ''),
          name: String(categoryForm.name || ''),
          dictType: String(categoryForm.dictType || 'BUSINESS'),
          sort: Number(categoryForm.sort ?? 0) || 0,
          remark: categoryForm.remark ? String(categoryForm.remark) : undefined
        }
        await dictService.saveCategory(dto)
        ElMessage.success('字典分类新增成功')
      } else {
        const dto: DictCategoryUpdateDTO = {
          id: String(categoryForm.id || ''),
          code: String(categoryForm.code || ''),
          name: String(categoryForm.name || ''),
          dictType: String(categoryForm.dictType || 'BUSINESS'),
          sort: Number(categoryForm.sort ?? 0) || 0,
          remark: categoryForm.remark ? String(categoryForm.remark) : undefined
        }
        await dictService.updateCategory(dto)
        ElMessage.success('字典分类修改成功')
      }
      categoryDialogVisible.value = false
      await loadCategories()
    } catch (e) {
      ElMessage.error(getErrorMessage(e, '保存失败'))
    } finally {
      categorySaving.value = false
    }
  }

  const toggleCategoryEnable = async (row: DictCategoryVO) => {
    const id = String(row.id || '')
    if (!id) return
    try {
      const nextEnabled = !row.status
      const ok = await ElMessageBox.confirm(
        nextEnabled ? '确认启用该字典分类吗？' : '确认停用该字典分类吗？',
        '提示',
        {
          confirmButtonText: '确定',
          cancelButtonText: '取消',
          type: 'warning'
        }
      ).catch(() => false)
      if (!ok) return
      if (nextEnabled) await dictService.enableCategory(id)
      else await dictService.disableCategory(id)
      ElMessage.success(nextEnabled ? '字典分类已启用' : '字典分类已停用')
      await loadCategories()
    } catch (e) {
      ElMessage.error(getErrorMessage(e, '操作失败'))
    } finally {
      categoriesLoading.value = false
    }
  }

  const doCategoryDelete = async (row: DictCategoryVO) => {
    try {
      await ElMessageBox.confirm(`确认删除字典分类“${row.name}”吗？`, '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      })
      await dictService.removeCategory(row.id)
      ElMessage.success('字典分类删除成功')
      if (selectedCategoryId.value === row.id) {
        selectedCategoryId.value = ''
      }
      await loadCategories()
    } catch (e) {
      if (e !== 'cancel') {
        ElMessage.error(getErrorMessage(e, '删除失败'))
      }
    }
  }

  const editDialogVisible = ref(false)
  const editMode = ref<'add' | 'edit'>('add')
  const editFormRef = ref<FormInstance>()
  const saving = ref(false)

  const editForm = reactive<Partial<DictSaveDTO & DictUpdateDTO>>({
    id: '',
    categoryId: '',
    label: '',
    value: '',
    sort: 0,
    remark: ''
  })

  const editRules: FormRules = {
    label: [{ required: true, message: '请输入标签', trigger: 'blur' }],
    value: [{ required: true, message: '请输入键值', trigger: 'blur' }],
    sort: [{ required: true, message: '请输入排序号', trigger: 'blur' }]
  }

  const openAdd = () => {
    if (!selectedCategoryId.value) return
    if (!ensureSelectedCategoryEnabled()) return
    editMode.value = 'add'
    Object.assign(editForm, { id: '', categoryId: selectedCategoryId.value, label: '', value: '', sort: 0, remark: '' })
    editDialogVisible.value = true
    editFormRef.value?.clearValidate()
  }

  const openEdit = (row: DictVO) => {
    if (!ensureSelectedCategoryEnabled()) return
    editMode.value = 'edit'
    Object.assign(editForm, {
      id: String(row.id || ''),
      categoryId: String(row.categoryId || selectedCategoryId.value || ''),
      label: String(row.label || ''),
      value: String(row.value || ''),
      sort: Number(row.sort ?? 0) || 0,
      remark: row.remark || ''
    })
    editDialogVisible.value = true
    editFormRef.value?.clearValidate()
  }

  const submitEdit = async () => {
    if (!editFormRef.value) return
    if (!ensureSelectedCategoryEnabled()) return
    const valid = await editFormRef.value.validate().catch(() => false)
    if (!valid) return
    saving.value = true
    try {
      if (editMode.value === 'add') {
        const dto: DictSaveDTO = {
          categoryId: String(editForm.categoryId || selectedCategoryId.value || ''),
          label: String(editForm.label || ''),
          value: String(editForm.value || ''),
          sort: Number(editForm.sort ?? 0) || 0,
          remark: editForm.remark ? String(editForm.remark) : undefined
        }
        await dictService.save(dto)
        ElMessage.success('字典项新增成功')
      } else {
        const dto: DictUpdateDTO = {
          id: String(editForm.id || ''),
          categoryId: String(editForm.categoryId || selectedCategoryId.value || ''),
          label: String(editForm.label || ''),
          value: String(editForm.value || ''),
          sort: Number(editForm.sort ?? 0) || 0,
          remark: editForm.remark ? String(editForm.remark) : undefined
        }
        await dictService.update(dto)
        ElMessage.success('字典项修改成功')
      }
      editDialogVisible.value = false
      await loadItems()
    } catch (e) {
      ElMessage.error(getErrorMessage(e, '保存失败'))
    } finally {
      saving.value = false
    }
  }

  const toggleEnable = async (row: DictVO) => {
    const id = String(row.id || '')
    if (!id) return
    try {
      const nextEnabled = !row.status
      const msg = nextEnabled ? '确认启用该字典项吗？' : '确认停用该字典项吗？'
      const ok = await ElMessageBox.confirm(msg, '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).catch(() => false)
      if (!ok) return
      if (nextEnabled) await dictService.enable(id)
      else await dictService.disable(id)
      ElMessage.success('操作成功')
      await loadItems()
    } catch (e) {
      ElMessage.error(getErrorMessage(e, '操作失败'))
    }
  }

  const doDelete = async (row: DictVO) => {
    const id = String(row.id || '')
    if (!id) return
    try {
      const ok = await ElMessageBox.confirm('确认删除该字典项吗？', '提示', {
        confirmButtonText: '确定',
        cancelButtonText: '取消',
        type: 'warning'
      }).catch(() => false)
      if (!ok) return
      await dictService.remove(id)
      ElMessage.success('删除成功')
      await loadItems()
    } catch (e) {
      ElMessage.error(getErrorMessage(e, '删除失败'))
    }
  }

  onMounted(async () => {
    await loadCategories()
  })

  return {
    categoriesLoading,
    itemsLoading,
    categories,
    tableData,
    selectedCategoryId,
    filterForm,
    selectedCategory,
    selectedCategoryEnabled,
    filteredItems,
    loadItems,
    selectCategory,
    refreshCategories,
    resetFilters,
    categoryDialogVisible,
    categoryEditMode,
    categoryFormRef,
    categorySaving,
    categoryForm,
    categoryRules,
    openCategoryAdd,
    openCategoryEdit,
    submitCategoryEdit,
    toggleCategoryEnable,
    doCategoryDelete,
    editDialogVisible,
    editMode,
    editFormRef,
    saving,
    editForm,
    editRules,
    openAdd,
    openEdit,
    submitEdit,
    toggleEnable,
    doDelete
  }
}
