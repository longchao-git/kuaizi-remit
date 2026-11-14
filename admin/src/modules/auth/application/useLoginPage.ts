import { reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import type { LoginForm } from '../domain/LoginForm'
import { getErrorMessage } from '@/utils/error'
import { useSessionService } from '@/shared/factory/session'

export function useLoginPage() {
  const route = useRoute()
  const router = useRouter()
  const sessionService = useSessionService()

  const loginForm = reactive<LoginForm>({ username: '', password: '' })
  const loading = ref(false)
  const agreedToTerms = ref(false)

  const handleLogin = async () => {
    if (loading.value) return

    try {
      loading.value = true
      await sessionService.loginWithInitialization(loginForm, agreedToTerms.value)
      const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/')
        ? route.query.redirect
        : '/system'
      await router.replace(redirect)
      ElMessage.success('登录成功')
    } catch (error) {
      ElMessage.error(getErrorMessage(error, '登录失败'))
    } finally {
      loading.value = false
    }
  }

  return {
    loginForm,
    loading,
    agreedToTerms,
    handleLogin
  }
}
