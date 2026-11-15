<template>
  <el-container class="layout">
    <el-container class="body">
      <el-aside class="aside" :width="`${asideWidth}px`">
        <div class="aside-shell">
          <SidebarMenu
            :menus="primaryMenus"
            :active-primary-path="activePrimaryPath"
            @select="goTo"
          />
          <SecondarySidebar
            v-if="showSecondarySidebar"
            :sections="secondarySections"
            :active-path="activeMenuPath"
            @select="goTo"
          />
        </div>
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
            <div v-else class="page-header__title">{{ currentPageTitle }}</div>
          </div>
          <div class="page-header__right">
            <el-dropdown trigger="click" @command="onUserCommand">
              <div class="page-header__user-trigger">
                <span class="page-header__user-name">{{ userDisplayName }}</span>
                <el-icon class="page-header__user-arrow"><ArrowDown /></el-icon>
              </div>
              <template #dropdown>
                <el-dropdown-menu>
                  <el-dropdown-item command="account">我的账号</el-dropdown-item>
                  <el-dropdown-item command="logout">退出</el-dropdown-item>
                </el-dropdown-menu>
              </template>
            </el-dropdown>
          </div>
        </el-header>

        <el-main class="main">
          <router-view />
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
import SecondarySidebar from '@/components/menu/SecondarySidebar.vue'
import {
  findMenuChainByPath,
  getActivePrimaryMenu,
  getPrimaryMenus,
  getSecondaryMenuSections,
  resolveCurrentPageTitle,
  resolveLayoutActivePath,
  resolveMenuChainForRoute
} from '@/shared/utils/menuTree'

defineOptions({ name: 'AppLayout' })

const PRIMARY_SIDEBAR_WIDTH = 120
const SECONDARY_SIDEBAR_WIDTH = 144

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const sessionService = useSessionService()

const username = computed(() => userStore.profile?.username || userStore.profile?.nickname || '')
const userDisplayName = computed(() => username.value || '未登录')
const primaryMenus = computed(() => getPrimaryMenus(userStore.menus))
const activePrimaryMenu = computed(() => getActivePrimaryMenu(userStore.menus, route.path))
const activePrimaryPath = computed(() => String(activePrimaryMenu.value?.path || ''))
const secondarySections = computed(() => getSecondaryMenuSections(activePrimaryMenu.value))
const activeMenuPath = computed(() => resolveLayoutActivePath(userStore.menus, route.path))
const showSecondarySidebar = computed(() => secondarySections.value.length > 0)
const asideWidth = computed(() => PRIMARY_SIDEBAR_WIDTH + (showSecondarySidebar.value ? SECONDARY_SIDEBAR_WIDTH : 0))
const exactMenuChain = computed(() => findMenuChainByPath(userStore.menus, route.path))
const currentMenuChain = computed(() => resolveMenuChainForRoute(userStore.menus, route.path))
const currentPageTitle = computed(() => resolveCurrentPageTitle(userStore.menus, route.path) || 'Template Admin')
const showBackButton = computed(() => exactMenuChain.value.length === 0 && currentMenuChain.value.length > 0)
const routePageTitle = computed(() => String(route.meta?.title || '').trim())
const superiorTitle = computed(() => (showBackButton.value ? currentPageTitle.value : ''))
const menuSecondTitle = computed(() => {
  if (!showBackButton.value) return currentPageTitle.value
  return routePageTitle.value || currentPageTitle.value
})

const goAccount = () => {
  router.push('/system/account')
}

const onUserCommand = (command: string | number | object) => {
  const action = String(command)
  if (action === 'account') {
    goAccount()
    return
  }
  if (action === 'logout') {
    void doLogout()
  }
}

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

const doLogout = async () => {
  await sessionService.logout()
  router.replace('/login')
}
</script>

<style scoped>
.layout {
  height: 100vh;
  background: var(--omc-color-surface-soft);
}

.body {
  height: 100vh;
}

.aside {
  height: 100%;
  flex: 0 0 auto;
  background: var(--omc-color-surface);
}

.aside-shell {
  width: 100%;
  height: 100%;
  display: flex;
  overflow: hidden;
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
  height: 66px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
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

.page-header__title {
  font-size: 14px;
  color: var(--omc-color-text);
}

.page-header__right {
  display: flex;
  align-items: center;
  gap: 10px;
}

.page-header__user-trigger {
  padding: 10px 20px;
  height: 50px;
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
  padding: 16px;
  background: transparent;
  flex: 0 0 auto;
  overflow: visible;
}
</style>
