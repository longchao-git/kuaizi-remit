<template>
  <div class="login-page">
    <aside class="login-intro">
      <div class="login-brand">
        <span class="login-brand__mark" aria-hidden="true">KZ</span>
        <span>筷子闪汇</span>
      </div>
      <div class="login-intro__copy">
        <p class="login-intro__title">让每一笔跨境汇款<br>清晰、可控、可追踪</p>
        <p>在一个后台管理汇率、手续费、到账进度和客户服务。</p>
      </div>
    </aside>

    <main class="login-page__main">
      <section class="login-card" v-loading="loading" element-loading-text="正在登录...">
        <form class="login-card__content" @submit.prevent="handleLogin">
          <div class="login-brand login-brand--card">
            <span class="login-brand__mark" aria-hidden="true">KZ</span>
            <span>筷子闪汇</span>
          </div>
          <h1 class="login-card__title">欢迎回来</h1>

          <div class="login-card__field">
            <label for="platform-login-username" class="login-card__label">后台账号</label>
            <el-input
              id="platform-login-username"
              v-model="loginForm.username"
              class="login-card__input"
              placeholder="请输入账号"
              autocomplete="username"
            />
          </div>

          <div class="login-card__field">
            <label for="platform-login-password" class="login-card__label">密码</label>
            <el-input
              id="platform-login-password"
              v-model="loginForm.password"
              class="login-card__input"
              type="password"
              show-password
              placeholder="请输入密码"
              autocomplete="current-password"
            />
          </div>

          <el-checkbox v-model="agreedToTerms" class="login-card__agreement">
            我已阅读并同意用户协议和隐私政策
          </el-checkbox>

          <el-button native-type="submit" type="primary" class="login-card__button" :loading="loading">
            登录
          </el-button>
        </form>
      </section>
    </main>

  </div>
</template>

<script setup lang="ts">
import { useLoginPage } from '../application/useLoginPage'

const { loginForm, loading, agreedToTerms, handleLogin } = useLoginPage()
</script>

<style scoped lang="scss">
.login-page {
  display: grid;
  position: relative;
  overflow: hidden;
  grid-template-columns: minmax(290px, 48%) minmax(0, 1fr);
  min-height: 100vh;
  background: #fdfefe;
  color: var(--omc-color-text);
}

.login-intro {
  position: relative;
  isolation: isolate;
  display: flex;
  flex-direction: column;
  padding: clamp(32px, 5vw, 76px);
  background: var(--omc-color-sidebar-top);
  color: #fff;
}

.login-intro::before,
.login-intro::after {
  position: absolute;
  z-index: -1;
  width: min(70vw, 710px);
  aspect-ratio: 1;
  border-radius: 50%;
  content: '';
}

.login-intro::before {
  top: -30%;
  right: -38%;
  background: #216970;
}

.login-intro::after {
  bottom: -18%;
  left: -8%;
  background: #1a5063;
}

.login-brand {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  font-weight: 700;
  font-size: 17px;
}

.login-brand__mark {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex: 0 0 auto;
  border-radius: 13px;
  background: var(--omc-color-primary);
  color: #fff;
  font-size: 14px;
  font-weight: 700;
}

.login-intro__copy {
  max-width: 440px;
  margin-top: clamp(70px, 12vh, 125px);
  color: #c8dbe4;
  font-size: 15px;
  line-height: 1.7;
}

.login-intro__title {
  margin: 0 0 18px;
  color: #fff;
  font-size: clamp(24px, 2vw, 34px);
  font-weight: 700;
  line-height: 1.45;
}

.login-page__main {
  position: relative;
  display: grid;
  place-items: center;
  padding: 32px clamp(24px, 5vw, 80px);
}

.login-card {
  box-sizing: border-box;
  width: min(100%, 528px);
  min-height: min(67vh, 670px);
  border: 1px solid var(--omc-color-border);
  border-radius: 18px;
  background: #fff;
}

.login-card__content {
  display: grid;
  padding: clamp(26px, 3.3vw, 54px);
}

.login-brand--card {
  color: #223b50;
}

.login-brand--card .login-brand__mark {
  background: var(--omc-color-primary-soft);
  color: var(--omc-color-primary);
}

.login-card__title {
  margin: clamp(26px, 5vh, 60px) 0 10px;
  color: #223b50;
  font-size: 26px;
}

.login-card__field { margin-top: 22px; }
.login-card__label { display: block; margin-bottom: 8px; color: #526779; font-size: 13px; }
.login-card__input { width: 100%; }

.login-card__input :deep(.el-input__wrapper) {
  min-height: 46px;
  border-radius: 8px;
  box-shadow: 0 0 0 1px var(--omc-color-border) inset;
}

.login-card__input :deep(.el-input__wrapper.is-focus) {
  box-shadow: 0 0 0 1px var(--omc-color-primary) inset;
}

.login-card__agreement {
  height: auto;
  margin-top: 24px;
  color: #526779;
}

.login-card__agreement :deep(.el-checkbox__label) {
  white-space: normal;
  line-height: 1.5;
}

.login-card__button {
  width: 100%;
  height: 46px;
  margin-top: 22px;
  border-radius: 8px;
  font-weight: 600;
}

@media (max-width: 720px) {
  .login-page { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
  .login-intro { overflow: hidden; padding: 20px 24px; }
  .login-intro__copy { display: none; }
  .login-page__main { padding: 24px 16px; }
  .login-card { min-height: 0; }
  .login-brand--card { display: none; }
  .login-card__title { margin-top: 0; }
}
</style>
