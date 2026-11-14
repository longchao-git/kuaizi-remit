<template>
  <div class="pagination-container-bg"></div>
  <div class="custom-pagination-container fixed-bottom" :style="{ left: leftValue }">
    <div class="total-info">
      共 {{ total }} 条
    </div>
    <el-pagination
      v-model:current-page="currentPage"
      v-model:page-size="pageSize"
      :page-sizes="pageSizes"
      :total="total"
      :layout="layout"
      :background="background"
      @size-change="handleSizeChange"
      @current-change="handleCurrentChange"
      class="custom-pagination"
    />
  </div>
</template>

<script lang="ts" setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { getActivePrimaryMenu, getSecondaryMenuSections } from '@/shared/utils/menuTree'

interface PageFooterProps {
  currentPage: number
  pageSize: number
  total: number
  pageSizes?: number[]
  layout?: string
  background?: boolean
}

interface Emits {
  (e: 'update:currentPage', value: number): void
  (e: 'update:pageSize', value: number): void
  (e: 'size-change', value: number): void
  (e: 'current-change', value: number): void
}

const PRIMARY_SIDEBAR_WIDTH = 120
const SECONDARY_SIDEBAR_WIDTH = 120

const props = withDefaults(defineProps<PageFooterProps>(), {
  pageSizes: () => [10, 20, 50, 100],
  layout: 'sizes, prev, pager, next, jumper',
  background: true
})

const emit = defineEmits<Emits>()
const route = useRoute()
const userStore = useUserStore()

const activePrimaryMenu = computed(() => getActivePrimaryMenu(userStore.menus, route.path))
const showSecondarySidebar = computed(() => getSecondaryMenuSections(activePrimaryMenu.value).length > 0)

const leftValue = computed(() => {
  return showSecondarySidebar.value
    ? `${PRIMARY_SIDEBAR_WIDTH + SECONDARY_SIDEBAR_WIDTH}px`
    : `${PRIMARY_SIDEBAR_WIDTH}px`
})

const currentPage = computed({
  get: () => props.currentPage,
  set: value => emit('update:currentPage', value)
})

const pageSize = computed({
  get: () => props.pageSize,
  set: value => emit('update:pageSize', value)
})

const handleSizeChange = (val: number) => {
  emit('size-change', val)
}

const handleCurrentChange = (val: number) => {
  emit('current-change', val)
}
</script>

<style lang="scss">
.pagination-container-bg {
  height: 76px;
  flex: 0 0 76px;
}

.custom-pagination-container {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  min-height: 64px;
  padding: 16px 24px 16px 32px;
  background: #fff;
  box-shadow: 0 -5px 5px -6px rgba(0, 0, 0, 0.18);
  box-sizing: border-box;

  &.fixed-bottom {
    position: fixed;
    bottom: 0px;
    right: 20px;
    z-index: 1000;
  }

  .total-info {
    flex: 0 0 auto;
    min-width: 96px;
    padding-left: 4px;
    font-size: 14px;
    color: #4d545f;
    white-space: nowrap;
  }
}

.custom-pagination {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex: 1 1 auto;

  &.el-pagination {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    justify-content: flex-end;
    row-gap: 8px;
  }

  .el-pagination__sizes,
  .el-pagination__jump,
  .btn-prev,
  .btn-next,
  .el-pager {
    display: inline-flex;
    align-items: center;
  }

  .el-pagination__jump {
    margin-left: 16px !important;
    gap: 8px;
    white-space: nowrap;
  }

  .el-pagination__goto,
  .el-pagination__classifier {
    display: inline-flex;
    align-items: center;
    line-height: 32px;
  }

  .el-pagination__total {
    font-size: 14px !important;
    color: rgba(0, 0, 0, 0.6) !important;
    margin-right: 16px !important;
  }

  .el-pagination__sizes {
    margin-right: 12px;

    .el-select {
      .el-input {
        .el-input__inner {
          border-radius: 4px !important;
          font-size: 14px !important;
        }
      }
    }
  }

  .el-pager {
    li {
      border-radius: 4px !important;
      margin: 0 2px !important;
      font-size: 14px !important;
      color: #606266 !important;
      border: 1px solid #dcdfe6 !important;
      background: #fff !important;
      min-width: 32px !important;
      height: 32px !important;
      line-height: 30px !important;

      &:hover {
        color: var(--omc-color-primary) !important;
        border-color: var(--omc-color-primary) !important;
      }

      &.is-active {
        background: var(--omc-color-primary) !important;
        color: #fff !important;
        border-color: var(--omc-color-primary) !important;
      }

      &.more {
        border: none !important;
        background: transparent !important;
        color: #606266 !important;

        &:hover {
          color: var(--omc-color-primary) !important;
          border: none !important;
          background: transparent !important;
        }
      }
    }
  }

  .btn-prev,
  .btn-next {
    border-radius: 4px !important;
    border: 1px solid #dcdfe6 !important;
    background: #fff !important;
    color: #606266 !important;
    font-size: 14px !important;
    min-width: 32px !important;
    height: 32px !important;
    line-height: 30px !important;

    &:hover {
      color: var(--omc-color-primary) !important;
      border-color: var(--omc-color-primary) !important;
    }

    &:disabled {
      color: #c0c4cc !important;
      border-color: #e4e7ed !important;
      background-color: #f5f7fa !important;
      cursor: not-allowed !important;
    }
  }
}
</style>
