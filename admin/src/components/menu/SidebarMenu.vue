<template>
  <div class="primary-sidebar">
    <div class="primary-sidebar__brand">
      <span class="primary-sidebar__logo" aria-hidden="true">KZ</span>
      <span class="primary-sidebar__brand-name">筷子闪汇</span>
    </div>
    <nav class="primary-sidebar__list" aria-label="后台导航">
      <div v-for="menu in menus" :key="menu.id || menu.path || menu.name" class="primary-sidebar__group">
        <button
          type="button"
          :class="['primary-sidebar__item', { 'is-active': isActive(menu) }]"
          :aria-expanded="hasVisibleChildren(menu) ? isActive(menu) : undefined"
          :aria-current="!hasVisibleChildren(menu) && isActive(menu) ? 'page' : undefined"
          @click="handleClick(menu)"
        >
          <img
            v-if="isImageIcon(menu.icon)"
            :src="String(menu.icon)"
            alt=""
            class="primary-sidebar__icon primary-sidebar__icon--image"
          />
          <component
            :is="resolveIcon(menu.icon)"
            v-else-if="menu.icon"
            class="primary-sidebar__icon"
            aria-hidden="true"
          />
          <span v-else class="primary-sidebar__initial" aria-hidden="true">{{ displayName(menu).slice(0, 1) }}</span>
          <span class="primary-sidebar__label">{{ displayName(menu) }}</span>
          <el-icon v-if="hasVisibleChildren(menu)" class="primary-sidebar__arrow" aria-hidden="true"><ArrowDown /></el-icon>
        </button>
        <SecondarySidebar
          v-if="isActive(menu) && hasVisibleChildren(menu)"
          :sections="getSecondaryMenuSections(menu)"
          :active-path="activePath"
          @select="emit('select', $event)"
        />
      </div>
    </nav>
  </div>
</template>

<script setup lang="ts">
import { resolveDynamicComponent } from 'vue'
import { ArrowDown } from '@element-plus/icons-vue'
import type { MenuVO } from '@/shared/types/menu'
import { getSecondaryMenuSections, hasVisibleChildren, resolveFirstVisibleMenuPath, resolveMenuDisplayName } from '@/shared/utils/menuTree'
import SecondarySidebar from './SecondarySidebar.vue'

defineOptions({ name: 'SidebarMenu' })

const props = defineProps<{
  menus: MenuVO[]
  activePrimaryPath: string
  activePath: string
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
  width: 100%;
  min-height: 100%;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: linear-gradient(180deg, var(--omc-color-sidebar-top), var(--omc-color-sidebar-bottom));
  color: var(--omc-color-surface);
}

.primary-sidebar__brand {
  min-height: 74px;
  margin-inline: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.12);
}

.primary-sidebar__logo {
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  flex: 0 0 38px;
  border-radius: 12px;
  background: var(--omc-color-primary);
  font-size: 12px;
  font-weight: 700;
}

.primary-sidebar__brand-name {
  font-size: 14px;
  font-weight: 600;
}

.primary-sidebar__list {
  flex: 1;
  padding: 14px 8px;
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.primary-sidebar__item {
  width: 100%;
  min-height: 36px;
  padding: 0 10px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 8px;
  border: 0;
  border-radius: var(--omc-radius-sm);
  background: transparent;
  color: var(--omc-color-sidebar-text);
  cursor: pointer;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.primary-sidebar__item:hover {
  background: var(--omc-color-sidebar-hover);
  color: var(--omc-color-surface);
}

.primary-sidebar__item.is-active {
  background: var(--omc-color-sidebar-active);
  box-shadow: inset 2px 0 var(--omc-color-sidebar-indicator);
  color: var(--omc-color-sidebar-active-text);
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

.primary-sidebar__initial {
  display: grid;
  place-items: center;
  width: 20px;
  height: 20px;
  flex: 0 0 20px;
  border-radius: 5px;
  background: rgba(255, 255, 255, 0.09);
  font-size: 11px;
}

.primary-sidebar__arrow {
  flex: 0 0 auto;
}

.primary-sidebar__item[aria-expanded="true"] .primary-sidebar__arrow {
  transform: rotate(180deg);
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
