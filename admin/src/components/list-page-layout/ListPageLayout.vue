<template>
  <div :class="['list-page-layout', { 'page-container': pageContainer }]">
    <div v-if="$slots.toolbar" class="list-page-layout__toolbar">
      <slot name="toolbar" />
    </div>

    <div v-if="$slots.search" :class="['list-page-layout__search', searchClass]">
      <slot name="search" />
    </div>

    <div :class="['table-container', 'list-page-layout__table', tableClass]">
      <div v-if="$slots.tableActions" class="table-actions list-page-layout__table-actions">
        <slot name="tableActions" />
      </div>

      <slot />
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup lang="ts">
interface ListPageLayoutProps {
  pageContainer?: boolean
  searchClass?: string
  tableClass?: string
}

withDefaults(defineProps<ListPageLayoutProps>(), {
  pageContainer: true,
  searchClass: '',
  tableClass: ''
})
</script>

<style scoped>
.list-page-layout {
  width: 100%;
  min-height: 100%;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}

.list-page-layout__toolbar {
  margin-bottom: 20px;
}

.list-page-layout__search {
  margin-bottom: 16px;
}

.list-page-layout__table {
  width: 100%;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}

.list-page-layout__table-actions {
  margin-bottom: 16px;
}

:deep(.el-table) {
  width: 100%;
}
</style>
