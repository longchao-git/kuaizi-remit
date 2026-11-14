<template>
  <div class="dict-container">
    <el-row :gutter="16">
      <el-col :span="7">
        <div class="category-panel">
          <div class="panel-header">
            <span>字典分类</span>
            <el-space :size="4">
              <el-button v-permission="'system:dict:add'" link type="primary" @click="openCategoryAdd">新增分类</el-button>
              <el-button link type="primary" :loading="categoriesLoading" @click="refreshCategories">刷新</el-button>
            </el-space>
          </div>
          <el-empty v-if="!categoriesLoading && categories.length === 0" description="暂无字典分类" />
          <div v-else v-loading="categoriesLoading" class="category-list">
            <div
              v-for="category in categories"
              :key="category.id"
              class="category-item"
              :class="{ active: category.id === selectedCategoryId }"
              @click="selectCategory(category.id)"
            >
              <div class="category-title-row">
                <span class="category-name">{{ category.name || '-' }}</span>
                <div class="category-actions">
                  <el-tag size="small" :type="category.status ? 'success' : 'info'" style="margin-right: 4px">{{ category.status ? '启用' : '停用' }}</el-tag>
                  <el-dropdown trigger="click" @click.stop>
                    <el-button link type="primary" size="small" @click.stop>
                      <el-icon><MoreFilled /></el-icon>
                    </el-button>
                    <template #dropdown>
                      <el-dropdown-menu>
                        <el-dropdown-item
                          v-permission="'system:dict:status'"
                          @click="toggleCategoryEnable(category)"
                        >
                          {{ category.status ? '停用分类' : '启用分类' }}
                        </el-dropdown-item>
                        <el-dropdown-item v-permission="'system:dict:edit'" @click="openCategoryEdit(category)">编辑分类</el-dropdown-item>
                        <el-dropdown-item v-permission="'system:dict:delete'" @click="doCategoryDelete(category)">删除分类</el-dropdown-item>
                      </el-dropdown-menu>
                    </template>
                  </el-dropdown>
                </div>
              </div>
              <div class="category-meta">{{ category.code || '-' }}</div>
            </div>
          </div>
        </div>
      </el-col>

      <el-col :span="17">
        <div class="table-container">
          <div class="panel-header table-header">
            <div>
              <div class="current-category-title">{{ selectedCategory?.name || '请选择字典分类' }}</div>
              <div class="current-category-meta">{{ selectedCategory?.code || '' }}</div>
            </div>
            <el-button
              v-permission="'system:dict:add'"
              type="primary"
              :disabled="!selectedCategoryId || !selectedCategoryEnabled"
              @click="openAdd"
            >
              新增字典项
            </el-button>
          </div>

          <SearchForm
            :options="searchOpt"
            :search-data="filterForm"
            @search="loadItems"
            @reset="resetFilters"
          />

          <BaseTable
            v-loading="itemsLoading"
            :data="filteredItems"
            :columns="tableColumns"
            style="width: 100%"
          />
        </div>
      </el-col>
    </el-row>

    <FormDialog
      v-model:visible="editDialogVisible"
      :title="editMode === 'add' ? '新增字典项' : '编辑字典项'"
      :loading="saving"
      @confirm="submitEdit"
    >
      <el-form ref="editFormRef" :model="editForm" :rules="editRules" label-width="100px" require-asterisk-position="left">
        <el-form-item label="字典分类">
          <el-input :model-value="selectedCategory?.name || '-'" disabled />
        </el-form-item>
        <el-form-item label="标签" prop="label">
          <el-input v-model="editForm.label" placeholder="请输入标签" />
        </el-form-item>
        <el-form-item label="键值" prop="value">
          <el-input v-model="editForm.value" placeholder="请输入键值" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="editForm.sort" :min="0" :max="999999" style="width: 240px" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="editForm.remark" type="textarea" :rows="3" placeholder="请输入备注" />
        </el-form-item>
      </el-form>
    </FormDialog>

    <FormDialog
      v-model:visible="categoryDialogVisible"
      :title="categoryEditMode === 'add' ? '新增分类' : '编辑分类'"
      width="500px"
      :loading="categorySaving"
      @confirm="submitCategoryEdit"
    >
      <el-form ref="categoryFormRef" :model="categoryForm" :rules="categoryRules" label-width="100px" require-asterisk-position="left">
        <el-form-item label="分类名称" prop="name">
          <el-input v-model="categoryForm.name" placeholder="请输入分类名称" />
        </el-form-item>
        <el-form-item label="分类编码" prop="code">
          <el-input v-model="categoryForm.code" placeholder="请输入分类编码" :disabled="categoryEditMode === 'edit'" />
        </el-form-item>
        <el-form-item label="分类类型">
          <el-select v-model="categoryForm.dictType" placeholder="请选择分类类型" style="width: 100%">
            <el-option label="系统字典" value="SYSTEM" />
            <el-option label="业务字典" value="BUSINESS" />
          </el-select>
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="categoryForm.sort" :min="0" :max="999999" style="width: 160px" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="categoryForm.remark" type="textarea" :rows="3" placeholder="请输入备注" />
        </el-form-item>
      </el-form>
    </FormDialog>
  </div>
</template>

<script setup lang="ts">
import { MoreFilled } from '@element-plus/icons-vue'
import SearchForm from '@/components/search-form/SearchForm.vue'
import BaseTable from '@/components/base-table/BaseTable.vue'
import FormDialog from '@/components/dialog/FormDialog.vue'
import { useDictPage } from '../application/useDictPage'
import { useDictSearchOptions } from '../application/useDictSearchOptions'
import { useDictTableColumns } from '../application/useDictTableColumns'

const {
  categoriesLoading,
  itemsLoading,
  categories,
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
} = useDictPage()

const searchOpt = useDictSearchOptions()
const tableColumns = useDictTableColumns(
  { openEdit, toggleEnable, doDelete },
  { selectedCategoryEnabled: () => selectedCategoryEnabled.value }
)
</script>

<style scoped>
.dict-container {
  background: #f5f7fa;
  min-height: 100%;
}

.category-panel,
.table-container {
  background: #fff;
  padding: 12px;
  border-radius: 8px;
}

.category-panel {
  min-height: 640px;
}

.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.table-header {
  margin-bottom: 16px;
}

.current-category-title {
  font-size: 18px;
  font-weight: 600;
  color: #303133;
}

.current-category-meta {
  margin-top: 4px;
  font-size: 12px;
  color: #909399;
}

.category-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.category-item {
  padding: 14px 12px;
  border: 1px solid #ebeef5;
  border-radius: 8px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.category-item:hover {
  border-color: var(--omc-color-primary-soft-border);
  background: var(--omc-color-primary-soft);
}

.category-item.active {
  border-color: var(--omc-color-primary);
  background: var(--omc-color-primary-soft);
}

.category-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.category-name {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
}

.category-actions {
  display: flex;
  align-items: center;
}

.category-meta {
  margin-top: 6px;
  font-size: 12px;
  color: #909399;
}
</style>
