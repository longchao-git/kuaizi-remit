<template>
  <div class="menu-container">
    <ListPageLayout>
      <template #toolbar>
        <el-button v-permission="'system:menu:add'" @click="handleAdd" type="primary">新增菜单</el-button>
      </template>

      <BaseTable
        v-loading="loading"
        :data="menuList"
        :columns="tableColumns"
        row-key="id"
        default-expand-all
        :indent="24"
        :tree-props="{ children: 'children', hasChildren: 'hasChildren' }"
      >
        <template #menuType="{ row }">
          <el-tag v-if="row.menuType === 'MENU'" type="primary" size="small">菜单</el-tag>
          <el-tag v-else-if="row.menuType === 'BUTTON'" type="warning" size="small">按钮</el-tag>
          <span v-else>-</span>
        </template>

        <template #icon="{ row }">
          <div v-if="row.icon" class="icon-cell">
            <el-icon v-if="isElementIcon(row.icon)" class="icon-cell__symbol">
              <component :is="row.icon" />
            </el-icon>
            <el-image
              v-else
              :src="row.icon"
              style="width: 30px; height: 30px"
              :preview-src-list="[row.icon]"
              fit="cover"
            />
          </div>
          <span v-else>-</span>
        </template>
      </BaseTable>
    </ListPageLayout>

    <FormDialog
      v-model:visible="dialogVisible"
      :title="dialogType === 'add' ? '新增菜单' : '编辑菜单'"
      width="600px"
      :loading="submitting"
      @confirm="handleSubmit"
    >
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px" require-asterisk-position="left">
        <el-form-item label="上级菜单" prop="parentId">
          <el-cascader
            v-model="parentMenuPath"
            :options="parentMenuOptions"
            :props="parentMenuCascaderProps"
            placeholder="请选择上级菜单"
            clearable
            filterable
            class="menu-parent-cascader"
          />
        </el-form-item>
        <el-form-item label="菜单名称" prop="name">
          <el-input v-model="formData.name" placeholder="请输入菜单名称" />
        </el-form-item>
        <el-form-item label="菜单类型">
          <el-select v-model="formData.menuType" class="menu-field-select">
            <el-option label="菜单" value="MENU" />
            <el-option label="按钮" value="BUTTON" />
          </el-select>
        </el-form-item>
        <el-form-item label="英文名称">
          <el-input v-model="formData.nameEn" placeholder="请输入英文名称" />
        </el-form-item>
        <el-form-item label="路由路径" prop="path">
          <el-input v-model="formData.path" placeholder="请输入路由路径" />
        </el-form-item>
        <el-form-item label="组件路径" prop="component">
          <el-input v-model="formData.component" placeholder="请输入组件路径" />
        </el-form-item>
        <el-form-item label="图标">
          <div class="menu-icon-picker">
            <div class="menu-icon-picker__preview-box">
              <template v-if="formData.icon">
                <span class="menu-icon-picker__delete" @click="clearIcon">
                  <el-icon>
                    <Delete />
                  </el-icon>
                </span>
                <el-icon v-if="isElementIcon(formData.icon)" class="menu-icon-picker__preview menu-icon-picker__preview--element">
                  <component :is="formData.icon" />
                </el-icon>
                <el-image v-else :src="formData.icon" class="menu-icon-picker__preview" :preview-src-list="[formData.icon]" fit="cover" />
              </template>
              <template v-else>
                <div class="menu-icon-picker__placeholder">未选择图标</div>
              </template>
            </div>
            <el-button @click="iconDialogVisible = true">
              选择图标
            </el-button>
          </div>
        </el-form-item>
        <el-form-item label="权限标识" prop="permission">
          <el-input v-model="formData.permission" placeholder="请输入权限标识" />
        </el-form-item>
        <el-form-item label="排序" prop="sort">
          <el-input-number v-model="formData.sort" :min="0" :max="999" />
        </el-form-item>
        <el-form-item label="显示状态">
          <el-switch v-model="formData.visible" active-text="显示在侧边栏" inactive-text="隐藏" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="formData.remark" type="textarea" :rows="3" placeholder="请输入备注" />
        </el-form-item>
      </el-form>
    </FormDialog>

    <ElementIconPickerDialog
      v-model:visible="iconDialogVisible"
      v-model="formData.icon"
    />
  </div>
</template>

<script setup lang="ts">
import { Delete } from '@element-plus/icons-vue'
import ListPageLayout from '@/components/list-page-layout/ListPageLayout.vue'
import BaseTable from '@/components/base-table/BaseTable.vue'
import FormDialog from '@/components/dialog/FormDialog.vue'
import ElementIconPickerDialog from '@/components/menu/ElementIconPickerDialog.vue'
import { useMenuPage } from '../application/useMenuPage'
import { useMenuTableColumns } from '../application/useMenuTableColumns'

const {
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
} = useMenuPage()

const tableColumns = useMenuTableColumns({ handleEdit, handleDelete })
</script>

<style scoped lang="scss">
.menu-container {
  :deep(.list-page-layout__table .el-table .el-table__row .el-table__expand-icon) {
    margin-right: 8px;
  }

  :deep(.menu-parent-cascader),
  :deep(.menu-field-select) {
    width: 240px;
    max-width: 100%;
  }

  .icon-cell {
    display: inline-flex;
    align-items: center;
    justify-content: center;
  }

  .icon-cell__symbol {
    font-size: 20px;
  }
}
</style>

<style lang="scss">
.menu-icon-picker {
  display: flex;
  align-items: center;
}

.menu-icon-picker__preview-box {
  position: relative;
  width: 88px;
  height: 88px;
  border: 1px dashed #e5e5e5;
  border-radius: 4px;
  overflow: visible;
  display: flex;
  align-items: center;
  justify-content: center;
  margin-right: 12px;
  background: #fff;
}

.menu-icon-picker__preview {
  width: 100%;
  height: 100%;
}

.menu-icon-picker__preview--element {
  font-size: 88px !important;
}

.menu-icon-picker__placeholder {
  color: #999;
  font-size: 12px;
  text-align: center;
  line-height: 1.4;
  padding: 0 6px;
}

.menu-icon-picker__delete {
  position: absolute;
  top: -10px;
  right: -10px;
  z-index: 20;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  cursor: pointer;
  color: #f56c6c;
  background: #fff;
  border-radius: 50%;
  box-shadow: none;
  border: none;
}

.menu-icon-picker__delete .el-icon {
  font-size: 16px;
}
</style>
