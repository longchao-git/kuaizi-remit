<template>
  <div class="my-account-container">
    <div class="account-form">
      <el-form :model="accountForm" label-width="120px">
        <el-form-item label="用户名:">
          <el-input
            v-model="accountForm.username"
            style="width: 240px"
            readonly
            class="readonly-input"
          />
        </el-form-item>

        <el-form-item label="密码:">
          <el-input
            v-model="accountForm.password"
            style="width: 240px"
            type="password"
            readonly
            class="readonly-input"
            show-password
          />
        </el-form-item>

        <el-form-item>
          <el-button type="primary" @click="openChangePasswordDialog">
            修改密码
          </el-button>
        </el-form-item>
      </el-form>
    </div>

    <FormDialog
      v-model:visible="changePasswordDialogVisible"
      title="修改密码"
      width="500px"
      :loading="changePasswordLoading"
      @confirm="handleChangePassword"
    >
      <el-form ref="passwordFormRef" :model="passwordForm" :rules="passwordRules" label-width="120px" require-asterisk-position="left">
        <el-form-item label="原密码:" prop="oldPassword">
          <el-input
            v-model="passwordForm.oldPassword"
            type="password"
            placeholder="请输入原密码"
            show-password
          />
        </el-form-item>

        <el-form-item label="新密码:" prop="newPassword">
          <el-input
            v-model="passwordForm.newPassword"
            type="password"
            placeholder="请输入新密码"
            show-password
          />
        </el-form-item>

        <el-form-item label="确认新密码:" prop="confirmPassword">
          <el-input
            v-model="passwordForm.confirmPassword"
            type="password"
            placeholder="请再次输入新密码"
            show-password
          />
        </el-form-item>
      </el-form>
    </FormDialog>
  </div>
</template>

<script lang="ts" setup>
import FormDialog from '@/components/dialog/FormDialog.vue'
import { useMyAccountPage } from '../application/useMyAccountPage'

const {
  accountForm,
  changePasswordDialogVisible,
  changePasswordLoading,
  passwordFormRef,
  passwordForm,
  passwordRules,
  openChangePasswordDialog,
  handleChangePassword
} = useMyAccountPage()
</script>

<style scoped lang="scss">
.account-form {
  margin: 0 auto;
  padding: 40px;
  background: white;
}

.readonly-input {
  :deep(.el-input__wrapper) {
    background-color: #f5f7fa;
  }
}
</style>
