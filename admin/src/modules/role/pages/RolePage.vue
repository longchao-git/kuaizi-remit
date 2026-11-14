<template>
  <div class="role-container">
    <ListPageLayout>
      <template #toolbar>
        <el-button v-permission="'system:role:add'" type="primary" @click="handleAdd">新增角色</el-button>
      </template>

      <template #search>
        <SearchForm :options="searchOpt" :search-data="queryParams" @search="handleSearch" @reset="resetSearch" />
      </template>

      <BaseTable v-loading="loading" :data="tableData" :columns="tableColumns" style="width: 100%" />

      <template #footer>
        <PageFooter
          v-model:current-page="currentPage"
          v-model:page-size="pageSize"
          :page-sizes="[10, 20, 30, 50]"
          :total="total"
          @size-change="handleSizeChange"
          @current-change="handleCurrentChange"
        />
      </template>
    </ListPageLayout>

    <FormDialog
      v-model:visible="cloneDialogVisible"
      title="克隆角色"
      width="520px"
      :loading="cloning"
      @confirm="submitClone"
    >
      <el-form ref="cloneFormRef" :model="cloneForm" :rules="cloneRules" label-width="110px" require-asterisk-position="left">
        <el-form-item label="角色名称"><span>{{ cloneSourceRole?.name }}</span></el-form-item>
        <el-form-item label="新角色名称" prop="name">
          <el-input v-model="cloneForm.name" placeholder="请输入克隆后的角色名称" />
        </el-form-item>
      </el-form>
    </FormDialog>
  </div>
</template>

<script setup lang="ts">
import ListPageLayout from '@/components/list-page-layout/ListPageLayout.vue'
import PageFooter from '@/components/page-footer/PageFooter.vue'
import SearchForm from '@/components/search-form/SearchForm.vue'
import BaseTable from '@/components/base-table/BaseTable.vue'
import FormDialog from '@/components/dialog/FormDialog.vue'
import { useRolePage } from '../application/useRolePage'
import { useRoleSearchOptions } from '../application/useRoleSearchOptions'
import { useRoleTableColumns } from '../application/useRoleTableColumns'

const {
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
} = useRolePage()

const searchOpt = useRoleSearchOptions()
const tableColumns = useRoleTableColumns(
  { handleEdit, handleAssignPermissions, openClone, handleDelete },
  { superAdminName }
)
</script>

<style scoped lang="scss">
.role-container {
  .table-container {
    .pagination-container {
      margin-top: 20px;
      display: flex;
      justify-content: flex-end;
    }
  }
}
</style>
