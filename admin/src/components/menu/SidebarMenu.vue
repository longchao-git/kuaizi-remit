<template>
  <div class="primary-sidebar">
    <div class="primary-sidebar__brand">
      <img src="@/assets/icon/headerLogo.png" alt="logo" class="primary-sidebar__logo" />
    </div>
    <div class="primary-sidebar__list">
      <button
        v-for="menu in menus"
        :key="menu.id || menu.path || menu.name"
        type="button"
        :class="['primary-sidebar__item', { 'is-active': isActive(menu) }]"
        @click="handleClick(menu)"
      >
        <img
          v-if="isImageIcon(menu.icon)"
          :src="String(menu.icon)"
          alt="menu icon"
          class="primary-sidebar__icon primary-sidebar__icon--image"
        />
        <component
          :is="resolveIcon(menu.icon)"
          v-else-if="menu.icon"
          class="primary-sidebar__icon"
        />
        <span class="primary-sidebar__label">{{ displayName(menu) }}</span>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { resolveDynamicComponent } from 'vue'
import type { MenuVO } from '@/shared/types/menu'
import { resolveFirstVisibleMenuPath, resolveMenuDisplayName } from '@/shared/utils/menuTree'

defineOptions({ name: 'SidebarMenu' })

const props = defineProps<{
  menus: MenuVO[]
  activePrimaryPath: string
}>()

const emit = defineEmits<{
  (e: 'select', path: string): void
}>()

const displayName = (menu: MenuVO) => resolveMenuDisplayName(menu)

const isImageIcon = (icon?: string) => {
  const value = String(icon || '').trim()
  if (!value) return false
  return /^(https?:\/\/|\/|data:image\/)/i.test(value)
}

const resolveIcon = (icon?: string) => {
  const value = String(icon || '').trim()
  return value ? resolveDynamicComponent(value) : null
}

const isActive = (menu: MenuVO) => {
  return String(menu.path || '') === props.activePrimaryPath
}

const handleClick = (menu: MenuVO) => {
  const nextPath = resolveFirstVisibleMenuPath(menu)
  if (!nextPath) return
  emit('select', nextPath)
}
</script>

<style scoped>
.primary-sidebar {
  width: 120px;
  height: 100%;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: #202033;
  color: #fff;
}

.primary-sidebar__brand {
  height: 88px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.primary-sidebar__logo {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  object-fit: cover;
  margin-top: 24px;
}

.primary-sidebar__list {
  flex: 1;
  padding: 28px 10px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.primary-sidebar__item {
  width: 100%;
  min-height: 38px;
  padding: 0 6px 0 12px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  border: 0;
  border-radius: 2px;
  background: transparent;
  color: rgba(255, 255, 255, 0.78);
  cursor: pointer;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.primary-sidebar__item:hover {
  background: var(--omc-color-primary);
  color: #fff;
}

.primary-sidebar__item.is-active {
  background: var(--omc-color-primary);
  color: #fff;
}

.primary-sidebar__icon {
  width: 16px;
  height: 16px;
  flex-shrink: 0;
}

.primary-sidebar__icon--image {
  object-fit: cover;
  border-radius: 4px;
}

.primary-sidebar__label {
  flex: 1;
  font-size: 14px;
  font-weight: 500;
  text-align: left;
  line-height: 16px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
