<template>
  <div class="dashboard-container">
    <el-row :gutter="16" class="summary-row">
      <el-col :xs="24" :sm="12" :lg="8">
        <el-card class="summary-card" shadow="hover">
          <div class="summary-label">当前账号</div>
          <div class="summary-value">{{ displayName }}</div>
          <div class="summary-meta">{{ usernameText }}</div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="8">
        <el-card class="summary-card" shadow="hover">
          <div class="summary-label">菜单节点</div>
          <div class="summary-value">{{ menuCount }}</div>
          <div class="summary-meta">已从后端动态加载</div>
        </el-card>
      </el-col>
      <el-col :xs="24" :sm="12" :lg="8">
        <el-card class="summary-card" shadow="hover">
          <div class="summary-label">权限标识</div>
          <div class="summary-value">{{ permissionCount }}</div>
          <div class="summary-meta">当前登录用户可用</div>
        </el-card>
      </el-col>
    </el-row>

    <el-card class="content-card trend-card" shadow="never">
      <template #header>
        <div class="card-header">数据走势图</div>
      </template>
      <MyEcharts :options="trendChartOptions" height="360px" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import MyEcharts from '@/components/echarts/MyEcharts.vue'
import { useDashboardPage } from '../application/useDashboardPage'

const { displayName, usernameText, menuCount, permissionCount, trendChartOptions } = useDashboardPage()
</script>

<style scoped>
.dashboard-container {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.summary-row {
  margin: 0 !important;
}

.summary-row > :deep(.el-col:first-child) {
  padding-left: 0 !important;
}

.summary-row > :deep(.el-col:last-child) {
  padding-right: 0 !important;
}

.summary-card,
.content-card {
  height: 100%;
  border: none;
}

.summary-label {
  font-size: 13px;
  color: #909399;
}

.summary-value {
  margin-top: 10px;
  font-size: 30px;
  font-weight: 700;
  color: #303133;
}

.summary-meta {
  margin-top: 8px;
  font-size: 13px;
  color: #606266;
}

.card-header {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}

.trend-card :deep(.el-card__body) {
  padding-top: 8px;
}

@media (max-width: 768px) {
  .summary-value {
    font-size: 24px;
  }
}
</style>
