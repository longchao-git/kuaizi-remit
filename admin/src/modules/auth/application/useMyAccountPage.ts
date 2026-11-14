import { computed, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import { useRouter } from 'vue-router'
import { getErrorMessage } from '@/utils/error'
import { useUserStore } from '@/stores/user'
import { useAuthService } from '@/shared/factory/auth'
import { useSessionService } from '@/shared/factory/session'

export function useMyAccountPage() {
  const authService = useAuthService()
  const sessionService = useSessionService()
  const userStore = useUserStore()
  const router = useRouter()

  const changePasswordDialogVisible = ref(false)
  const changePasswordLoading = ref(false)
  const passwordFormRef = ref<FormInstance>()

  const accountForm = reactive({
    username: '',
    password: '************'
  })

  const passwordForm = reactive({
    oldPassword: '',
    newPassword: '',
    confirmPassword: ''
  })

  const passwordRules = computed<FormRules>(() => ({
    oldPassword: [
      { required: true, message: '请输入原密码', trigger: 'blur' }
    ],
    newPassword: [
      { required: true, message: '请输入新密码', trigger: 'blur' },
      { min: 8, max: 20, message: '密码长度必须为 8-20 位', trigger: 'blur' }
    ],
    confirmPassword: [
      { required: true, message: '请再次输入新密码', trigger: 'blur' },
      {
        validator: (_rule, value, callback) => {
          if (value !== passwordForm.newPassword) {
            callback(new Error('两次输入的密码不一致'))
          } else {
            callback()
          }
        },
        trigger: 'blur'
      }
    ]
  }))

  const initAccountInfo = () => {
    accountForm.username = userStore.profile?.username || ''
  }

  const openChangePasswordDialog = () => {
    changePasswordDialogVisible.value = true
    passwordForm.oldPassword = ''
    passwordForm.newPassword = ''
    passwordForm.confirmPassword = ''
  }

  const handleChangePassword = async () => {
    if (!passwordFormRef.value) return

    try {
      await passwordFormRef.value.validate()
      changePasswordLoading.value = true
      await authService.changePassword({
        oldPassword: passwordForm.oldPassword,
        newPassword: passwordForm.newPassword
      })
      ElMessage.success('密码修改成功，请重新登录')
      changePasswordDialogVisible.value = false
      sessionService.clearSession()
      router.push('/login')
    } catch (error) {
      if (error !== 'cancel') {
        ElMessage.error(getErrorMessage(error, '修改密码失败'))
      }
    } finally {
      changePasswordLoading.value = false
    }
  }

  initAccountInfo()

  return {
    accountForm,
    changePasswordDialogVisible,
    changePasswordLoading,
    passwordFormRef,
    passwordForm,
    passwordRules,
    openChangePasswordDialog,
    handleChangePassword
  }
}
