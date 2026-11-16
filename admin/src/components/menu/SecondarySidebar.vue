<template>
  <div class="secondary-sidebar">
    <div v-for="section in sections" :key="section.key" class="secondary-sidebar__section">
      <div v-if="section.title" class="secondary-sidebar__title">{{ section.title }}</div>
      <button
        v-for="item in section.items"
        :key="item.id || item.path || item.name"
        type="button"
        :class="['secondary-sidebar__item', { 'is-active': isActive(item) }]"
        :aria-current="isActive(item) ? 'page' : undefined"
        @click="handleClick(item)"
      >
        {{ displayName(item) }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { MenuVO } from '@/shared/types/menu'
import type { SecondaryMenuSection } from '@/shared/utils/menuTree'
import { resolveMenuDisplayName, resolveMenuIndex } from '@/shared/utils/menuTree'

defineOptions({ name: 'SecondarySidebar' })

const props = defineProps<{
  sections: SecondaryMenuSection[]
  activePath: string
}>()

const emit = defineEmits<{
  (e: 'select', path: string): void
}>()

const displayName = (menu: MenuVO) => resolveMenuDisplayName(menu)

const isActive = (menu: MenuVO) => resolveMenuIndex(menu) === props.activePath

const handleClick = (menu: MenuVO) => {
  emit('select', resolveMenuIndex(menu))
}
</script>

<style scoped>
.secondary-sidebar {
  margin: 4px 0 6px 20px;
  padding-inline-start: 6px;
  border-inline-start: 1px solid rgba(255, 255, 255, 0.12);
}

.secondary-sidebar__section {
  padding: 0;
}

.secondary-sidebar__section + .secondary-sidebar__section {
  margin-top: 10px;
}

.secondary-sidebar__title {
  margin-bottom: 10px;
  padding: 0 8px;
  font-size: 14px;
  line-height: 22px;
  color: var(--omc-color-sidebar-text);
}

.secondary-sidebar__item {
  width: 100%;
  min-height: 34px;
  padding: 4px 10px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  border: 0;
  border-radius: var(--omc-radius-sm);
  background: transparent;
  color: var(--omc-color-sidebar-text);
  cursor: pointer;
  text-align: left;
  font-size: 13px;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.secondary-sidebar__item:hover {
  background: var(--omc-color-sidebar-hover);
  color: var(--omc-color-surface);
}

.secondary-sidebar__item.is-active {
  background: var(--omc-color-sidebar-active);
  color: var(--omc-color-sidebar-active-text);
  font-weight: 600;
}
</style>
