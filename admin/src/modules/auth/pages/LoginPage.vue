<template>
  <div class="login-page">
    <header class="login-page__brand">
      <div class="login-page__brand-mark">
        <img src="@/assets/icon/headerLogo.png" alt="logo" class="login-page__brand-logo" />
      </div>
      <div class="login-page__brand-title">后台管理系统</div>
    </header>

    <main class="login-page__main">
      <section class="login-card" v-loading="loading" element-loading-text="正在登录...">
        <div class="login-card__content">
          <h1 class="login-card__title">欢迎登录</h1>

          <div class="login-card__field">
            <label class="login-card__label">账号</label>
            <el-input
              v-model="loginForm.username"
              class="login-card__input"
              placeholder="请输入账号"
              autocomplete="username"
            />
          </div>

          <div class="login-card__field">
            <label class="login-card__label">密码</label>
            <el-input
              v-model="loginForm.password"
              class="login-card__input"
              type="password"
              show-password
              placeholder="请输入密码"
              autocomplete="current-password"
              @keyup.enter="handleLogin"
            />
          </div>

          <el-checkbox v-model="agreedToTerms" class="login-card__agreement">
            我已阅读并同意用户协议和隐私政策
          </el-checkbox>

          <el-button type="primary" class="login-card__button" :loading="loading" @click="handleLogin">
            登录
          </el-button>
        </div>
      </section>
    </main>

    <footer class="login-page__footer">
      <div>Copyright © 2026</div>
      <div>后台管理系统</div>
    </footer>
  </div>
</template>

<script setup lang="ts">
import { useLoginPage } from '../application/useLoginPage'

const { loginForm, loading, agreedToTerms, handleLogin } = useLoginPage()
</script>

<style lang="scss">
.login-card {
  .el-input__wrapper {
    min-height: 40px !important;
    padding: 0 12px !important;
    border-radius: 8px !important;
    background: #fff !important;
    box-shadow: 0 0 0 1px rgba(15, 23, 42, 0.08) !important;
    transition: box-shadow 0.2s ease;
  }

  .el-input__wrapper:hover,
  .el-input__wrapper.is-focus {
    box-shadow: 0 0 0 1px rgba(37, 99, 235, 0.36) !important;
  }

  .el-input__inner {
    height: 48px;
    font-size: 13px;
    color: #1f2937;
  }
}
</style>

<style scoped lang="scss">
.login-page {
  position: relative;
  height: 100vh;
  overflow: hidden;
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.72), rgba(243, 247, 255, 0.9)),
    url('@/assets/icon/backImage.png') center/cover no-repeat;
}

.login-page::before {
  content: '';
  position: absolute;
  inset: 0;
  background:
    radial-gradient(circle at 18% 18%, rgba(59, 130, 246, 0.12), transparent 28%),
    radial-gradient(circle at 82% 20%, rgba(147, 197, 253, 0.18), transparent 32%),
    radial-gradient(circle at 50% 100%, rgba(191, 219, 254, 0.2), transparent 34%);
  pointer-events: none;
}

.login-page__brand,
.login-page__main,
.login-page__footer {
  position: relative;
  z-index: 1;
}

.login-page__brand {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 24px 0 0 28px;
}

.login-page__brand-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.86);
  box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
}

.login-page__brand-logo {
  width: 20px;
  height: 20px;
  object-fit: contain;
}

.login-page__brand-title {
  font-size: 15px;
  font-weight: 600;
  color: #111827;
}

.login-page__main {
  display: flex;
  align-items: center;
  justify-content: center;
  height: calc(100vh - 72px);
  padding: 20px 20px 72px;
  box-sizing: border-box;
}

.login-card {
  width: min(400px, calc(100vw - 32px));
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 24px 60px rgba(15, 23, 42, 0.1);
  backdrop-filter: blur(12px);
}

.login-card__content { padding: 32px; }
.login-card__title { margin: 0; font-size: 20px; font-weight: 600; color: #111827; }
.login-card__field { margin-top: 24px; }
.login-card__label { display: block; margin-bottom: 12px; font-size: 14px; color: #374151; }
.login-card__input { width: 100%; }
.login-card__agreement { margin-top: 18px; color: #64748b; }

.login-card__button {
  width: 100%;
  height: 48px !important;
  margin-top: 20px;
  border-radius: 8px !important;
  font-size: 14px;
  font-weight: 600;
}

.login-page__footer {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  height: 72px;
  padding: 12px 20px;
  box-sizing: border-box;
  font-size: 12px;
  color: #94a3b8;
  text-align: center;
}

@media (max-width: 520px) {
  .login-page__brand { padding: 18px 0 0 18px; }
  .login-card__content { padding: 28px 22px; }
}
</style>
