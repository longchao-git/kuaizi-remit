<template>
  <div class="secondary-sidebar">
    <div v-for="section in sections" :key="section.key" class="secondary-sidebar__section">
      <div v-if="section.title" class="secondary-sidebar__title">{{ section.title }}</div>
      <button
        v-for="item in section.items"
        :key="item.id || item.path || item.name"
        type="button"
        :class="['secondary-sidebar__item', { 'is-active': isActive(item) }]"
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
  width: 120px;
  height: 100%;
  padding: 30px 12px 28px;
  background: #fff;
  overflow-y: auto;
}

.secondary-sidebar__section {
  padding: 4px 0;
}

.secondary-sidebar__section + .secondary-sidebar__section {
  margin-top: 24px;
}

.secondary-sidebar__title {
  margin-bottom: 10px;
  padding: 0 8px;
  font-size: 14px;
  line-height: 22px;
  color: #999999;
}

.secondary-sidebar__item {
  width: 84px;
  min-height: 30px;
  padding: 4px 8px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  border: 0;
  border-radius: 2px;
  background: transparent;
  color: var(--omc-color-text);
  cursor: pointer;
  text-align: left;
  font-size: 14px;
  margin-bottom: 8px;
  transition: background-color 0.2s ease, color 0.2s ease;
}

.secondary-sidebar__item:hover {
  background: var(--omc-color-primary-soft);
  color: var(--omc-color-primary);
}

.secondary-sidebar__item.is-active {
  background: var(--omc-color-primary-soft);
  color: var(--omc-color-primary);
}
</style>
