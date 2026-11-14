<template>
  <div class="add-role-container">
    <div class="page-title">{{ pageTitle }}</div>
    <div class="form-container">
      <div class="form-section">
        <el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px" require-asterisk-position="left">
          <template v-if="!isPermissionMode">
            <el-form-item label="角色名称" prop="name">
              <el-input v-model="formData.name" placeholder="请输入内容" style="width: 300px" />
            </el-form-item>
            <el-form-item label="角色描述" prop="remark">
              <el-input
                v-model="formData.remark"
                type="textarea"
                placeholder="请输入内容"
                :rows="3"
                style="width: 300px"
              />
            </el-form-item>
          </template>
          <el-form-item v-if="showMenuPermissions" label="菜单权限" prop="menuIds">
            <div class="permission-panel">
              <div class="permission-toolbar">
                <el-input
                  v-model="filterText"
                  placeholder="搜索权限"
                  clearable
                  :prefix-icon="Search"
                  class="permission-search"
                />
                <div class="permission-actions">
                  <el-button link type="primary" @click="handleCheckAll">{{ isAllChecked ? '取消全选' : '全选' }}</el-button>
                  <el-divider direction="vertical" />
                  <el-button link type="primary" @click="handleExpandAll">{{ isAllExpanded ? '收起全部' : '展开全部' }}</el-button>
                </div>
              </div>
              <div class="permission-tree-wrapper">
                <el-tree
                  ref="menuTreeRef"
                  :data="menuTreeDisplayData"
                  show-checkbox
                  check-strictly
                  node-key="id"
                  :props="{ label: 'displayName', class: customNodeClass }"
                  :filter-node-method="filterNode"
                  :default-expand-all="true"
                  class="permission-tree"
                  @check="handleMenuCheck"
                >
                  <template #default="{ data }">
                    <span class="tree-node-label">{{ data.displayName }}</span>
                  </template>
                </el-tree>
              </div>
              <div class="permission-footer">已选择 {{ formData.menuIds.length }} 项权限</div>
            </div>
          </el-form-item>
          <div class="submit-bottom-bar-placeholder"></div>
          <div class="submit-bottom-bar is-fixed add-role-submit-bottom-bar">
            <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
            <el-button @click="handleCancel">取消</el-button>
          </div>
        </el-form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Search } from '@element-plus/icons-vue'
import { useRoleEditPage } from '../application/useRoleEditPage'

const {
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
  handleCancel
} = useRoleEditPage()
</script>

<style scoped lang="scss">
.add-role-container {
  min-height: 100%;
  padding: 24px;
  background: #fff;
  box-sizing: border-box;

  .page-title {
    padding: 0 24px 16px;
    font-size: 18px;
    font-weight: 600;
  }

  .form-section {
    padding: 24px 24px 0;
  }

  .permission-panel {
    width: 520px;
    border: 1px solid #dcdfe6;
    border-radius: 4px;
    background: #fff;

    .permission-toolbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 12px 16px;
      border-bottom: 1px solid #ebeef5;
      gap: 12px;

      .permission-search { width: 200px; }
      .permission-actions {
        display: flex;
        align-items: center;
        white-space: nowrap;
      }
    }

    .permission-tree-wrapper {
      max-height: 460px;
      overflow-y: auto;
      padding: 8px 16px;

      .permission-tree :deep(.is-button-node > .el-tree-node__content) .tree-node-label {
        color: #909399;
      }

      .tree-node-label {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 14px;
      }
    }

    .permission-footer {
      padding: 8px 16px;
      border-top: 1px solid #ebeef5;
      font-size: 12px;
      color: #909399;
    }
  }

  .add-role-submit-bottom-bar { left: 240px; }
}
</style>
