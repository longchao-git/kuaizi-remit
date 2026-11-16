<template>
  <el-container class="layout">
    <el-container class="body">
      <el-aside class="aside" width="var(--remit-sidebar-width)">
        <SidebarMenu
          :menus="primaryMenus"
          :active-primary-path="activePrimaryPath"
          :active-path="activeMenuPath"
          @select="goTo"
        />
      </el-aside>

      <el-container class="content-shell">
        <el-header class="page-header">
          <div class="page-header__title-wrap">
            <el-breadcrumb v-if="showBackButton" class="page-header__breadcrumb" separator="/">
              <el-breadcrumb-item class="page-header__breadcrumb-left" @click="goBack">
                <el-icon class="page-header__breadcrumb-icon"><ArrowLeft /></el-icon>
                <span>{{ superiorTitle }}</span>
              </el-breadcrumb-item>
              <el-breadcrumb-item class="page-header__breadcrumb-right">
                <span>{{ menuSecondTitle }}</span>
              </el-breadcrumb-item>
            </el-breadcrumb>
            <el-breadcrumb v-else class="page-header__breadcrumb" separator="/">
              <el-breadcrumb-item>跨境汇款</el-breadcrumb-item>
              <el-breadcrumb-item v-if="currentMenuChain.length > 1">{{ activePrimaryMenu?.name }}</el-breadcrumb-item>
              <el-breadcrumb-item>{{ currentPageTitle }}</el-breadcrumb-item>
            </el-breadcrumb>
          </div>
          <div class="page-header__right">
            <el-dropdown trigger="click" @command="onUserCommand">
              <button type="button" class="page-header__user-trigger">
                <span class="page-header__user-name">{{ userDisplayName }}</span>
                <el-icon class="page-header__user-arrow"><ArrowDown /></el-icon>
              </button>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="logout">退出登录</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </el-header>

        <el-main class="main">
          <el-empty v-if="route.path === '/system'" description="当前账号暂无可访问的后台页面" />
          <router-view v-else />
        </el-main>
      </el-container>
    </el-container>
  </el-container>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { ArrowDown, ArrowLeft } from '@element-plus/icons-vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useSessionService } from '@/shared/factory/session'
import SidebarMenu from '@/components/menu/SidebarMenu.vue'
import {
  findMenuChainByPath,
  getActivePrimaryMenu,
  getPrimaryMenus,
  resolveCurrentPageTitle,
  resolveLayoutActivePath,
  resolveMenuChainForRoute
} from '@/shared/utils/menuTree'

defineOptions({ name: 'AppLayout' })

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const sessionService = useSessionService()

const username = computed(() => userStore.profile?.username || userStore.profile?.nickname || '')
const userDisplayName = computed(() => username.value || '未登录')
const primaryMenus = computed(() => getPrimaryMenus(userStore.menus))
const activePrimaryMenu = computed(() => getActivePrimaryMenu(userStore.menus, route.path))
const activePrimaryPath = computed(() => String(activePrimaryMenu.value?.path || ''))
const activeMenuPath = computed(() => resolveLayoutActivePath(userStore.menus, route.path))
const exactMenuChain = computed(() => findMenuChainByPath(userStore.menus, route.path))
const currentMenuChain = computed(() => resolveMenuChainForRoute(userStore.menus, route.path))
const currentPageTitle = computed(() => resolveCurrentPageTitle(userStore.menus, route.path) || '管理后台')
const showBackButton = computed(() => exactMenuChain.value.length === 0 && currentMenuChain.value.length > 0)
const routePageTitle = computed(() => String(route.meta?.title || '').trim())
const superiorTitle = computed(() => (showBackButton.value ? currentPageTitle.value : ''))
const menuSecondTitle = computed(() => {
  if (!showBackButton.value) return currentPageTitle.value
  return routePageTitle.value || currentPageTitle.value
})

const goTo = (path: string) => {
  if (!path || path === route.path) return
  router.push(path)
}

const goBack = () => {
  const chain = currentMenuChain.value
  const parent = chain.at(-1)
  const fallback = parent?.path ? String(parent.path) : '/system'
  router.push(fallback)
}

const onUserCommand = async () => {
  await sessionService.logout()
  router.replace('/login')
}
</script>

<style scoped>
.layout {
  --remit-sidebar-width: 232px;
  height: 100vh;
  background: var(--omc-color-surface-soft);
}

.body {
  height: 100vh;
}

.aside {
  height: 100%;
  flex: 0 0 auto;
  background: var(--omc-color-sidebar-top);
  overflow-x: hidden;
}

.content-shell {
  min-width: 0;
  height: 100%;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  background: var(--omc-color-surface-soft);
}

.page-header {
  height: 68px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 32px;
  flex: 0 0 auto;
  background: var(--omc-color-surface);
  border-bottom: 1px solid var(--omc-color-border-light);
}

.page-header__title-wrap {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.page-header__breadcrumb {
  display: flex;
  align-items: center;
}

.page-header__breadcrumb :deep(.el-breadcrumb__inner),
.page-header__breadcrumb :deep(.el-breadcrumb__separator) {
  display: inline-flex;
  align-items: center;
  line-height: 20px;
}

.page-header__breadcrumb-left {
  cursor: pointer;
}

.page-header__breadcrumb-left :deep(.el-breadcrumb__inner) {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: var(--omc-color-text-secondary);
  font-weight: 400;
}

.page-header__breadcrumb-left:hover :deep(.el-breadcrumb__inner) {
  color: var(--omc-color-primary);
}

.page-header__breadcrumb-right :deep(.el-breadcrumb__inner) {
  color: var(--omc-color-text);
  font-weight: 500;
}

.page-header__breadcrumb :deep(.el-breadcrumb__separator) {
  color: #c0c4cc;
  margin: 0 6px;
}

.page-header__breadcrumb :deep(.el-breadcrumb__item:last-child .el-breadcrumb__separator) {
  display: none;
}

.page-header__breadcrumb-icon {
  font-size: 14px;
}

.page-header__right {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.page-header__user-trigger {
  padding: 10px 20px;
  min-height: 36px;
  max-width: 24vw;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border-radius: 999px;
  background: var(--omc-color-surface);
  color: var(--omc-color-text-secondary);
  border: 1px solid var(--omc-color-border-light);
  white-space: nowrap;
  cursor: pointer;
  box-sizing: border-box;
}

.page-header__user-name {
  overflow: hidden;
  text-overflow: ellipsis;
}

.page-header__user-arrow {
  flex: 0 0 auto;
  font-size: 12px;
  color: var(--omc-color-muted);
}

.main {
  padding: 24px clamp(16px, 2.4vw, 38px);
  background: transparent;
  flex: 0 0 auto;
  overflow: visible;
}

@media (max-width: 1100px) {
  .layout { --remit-sidebar-width: 200px; }
  .page-header { padding-inline: 20px; }
}

@media (max-width: 760px) {
  .layout { --remit-sidebar-width: 152px; }
  .page-header { padding-inline: 12px; }
}
</style>
