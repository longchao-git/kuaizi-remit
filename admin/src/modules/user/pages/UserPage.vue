<template>
  <div class="user-container">
    <ListPageLayout>
      <template #toolbar>
        <el-button v-permission="'system:user:add'" type="primary" @click="openAdd">新增用户</el-button>
      </template>

      <template #search>
        <SearchForm :options="searchOpt" :search-data="queryParams" @search="refresh" @reset="reset" />
      </template>

      <BaseTable
        v-loading="loading"
        :data="tableData"
        :columns="tableColumns"
        style="width: 100%"
      />

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
      v-model:visible="editDialogVisible"
      :title="editMode === 'add' ? '新增用户' : '编辑用户'"
      :loading="saving"
      @confirm="submitEdit"
    >
      <el-form ref="editFormRef" :model="editForm" :rules="editRules" label-width="100px" require-asterisk-position="left">
        <el-form-item label="用户名" prop="username">
          <el-input v-model="editForm.username" placeholder="请输入用户名" :disabled="editMode === 'edit'" />
        </el-form-item>
        <el-form-item v-if="editMode === 'add'" label="密码" prop="password">
          <el-input v-model="editForm.password" type="password" placeholder="请输入密码" show-password />
        </el-form-item>
        <el-form-item label="昵称">
          <el-input v-model="editForm.nickname" placeholder="请输入昵称" />
        </el-form-item>
        <el-form-item label="邮箱">
          <el-input v-model="editForm.email" placeholder="请输入邮箱" />
        </el-form-item>
        <el-form-item label="用户类型">
          <el-input :model-value="getUserTypeLabel(editForm.userType || 'NORMAL')" disabled style="width: 240px" />
        </el-form-item>
        <el-form-item v-if="editMode === 'add' && canAssignRole" label="角色" prop="roleIds">
          <el-select
            v-model="editForm.roleIds"
            multiple
            filterable
            clearable
            placeholder="请选择角色"
            style="width: 240px"
            popper-class="user-role-select-popper"
          >
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
        </el-form-item>
      </el-form>
    </FormDialog>

    <FormDialog
      v-if="canAssignRole"
      v-model:visible="roleDialogVisible"
      title="分配角色"
      width="520px"
      :loading="savingRoles"
      @confirm="submitRoles"
    >
      <el-form ref="roleFormRef" :model="roleForm" :rules="roleRules" label-width="90px" require-asterisk-position="left">
        <el-form-item label="用户名">
          <span>{{ roleDialogUser?.username }}</span>
        </el-form-item>
        <el-form-item label="角色" prop="roleIds">
          <el-select
            v-model="roleForm.roleIds"
            multiple
            filterable
            clearable
            placeholder="请选择角色"
            style="width: 240px"
            popper-class="user-role-select-popper"
          >
            <el-option v-for="r in roleOptions" :key="r.id" :label="r.name" :value="r.id" />
          </el-select>
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
import { useUserPage } from '../application/useUserPage'
import { useUserSearchOptions } from '../application/useUserSearchOptions'
import { useUserTableColumns } from '../application/useUserTableColumns'

const {
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
} = useUserPage()

const searchOpt = useUserSearchOptions()
const tableColumns = useUserTableColumns(
  getUserTypeLabel,
  { openEdit, openRoles, toggleStatus, resetPassword, doDelete },
  { currentUserId: () => currentUserId.value }
)
</script>

<style scoped lang="scss">
.user-container {
  height: 100%;
}
</style>
