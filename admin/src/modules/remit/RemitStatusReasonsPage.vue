<template>
  <section class="reasons">
    <h1>状态原因码</h1>
    <el-form class="search" inline @submit.prevent="load">
      <el-form-item label="关键词">
        <el-input v-model.trim="keyword" maxlength="100" clearable placeholder="原因码、客户提示或客服建议" />
      </el-form-item>
      <el-button type="primary" native-type="submit" :loading="loading">查询</el-button>
      <el-button :disabled="loading" @click="reset">重置</el-button>
    </el-form>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-card class="remit-card" shadow="never">
      <template #header><h2>原因码列表</h2></template>
      <el-table v-loading="loading" :data="rows" :empty-text="error ? '读取失败' : '没有匹配的原因码'">
        <el-table-column prop="code" label="原因码" min-width="215" show-overflow-tooltip />
        <el-table-column prop="title" label="客户提示" min-width="150" />
        <el-table-column prop="guidance" label="客服处理建议" min-width="230" />
      </el-table>
    </el-card>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { get } from '@/request/http'
import { getErrorMessage } from '@/utils/error'

interface StatusReason { code: string; title: string; guidance: string }
const rows = ref<StatusReason[]>([])
const keyword = ref('')
const error = ref('')
const loading = ref(false)

async function load() {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    rows.value = await get<StatusReason[]>('/admin/remittance/status-reasons', { keyword: keyword.value })
  } catch (reason) {
    rows.value = []
    error.value = getErrorMessage(reason, '读取状态原因码失败')
  } finally {
    loading.value = false
  }
}

function reset() {
  keyword.value = ''
  void load()
}

onMounted(() => void load())
</script>

<style scoped>
.reasons { display: grid; gap: 16px; }
.reasons h1 { margin: 0; }
.search { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; padding: 0; margin: 0; }
.search :deep(.el-form-item), .search .el-button { margin: 0; }
.search :deep(.el-input__wrapper), .search .el-button { height: var(--omc-control-height); }
.search .el-input { width: min(245px, 68vw); }
</style>
