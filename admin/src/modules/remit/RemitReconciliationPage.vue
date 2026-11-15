<template>
  <section class="reconciliation-page">
    <header class="toolbar">
      <h1>对账与结算</h1>
      <el-button @click="downloadTemplate">下载导入模板</el-button>
      <el-button type="primary" @click="openImport">导入对账 CSV</el-button>
    </header>
    <el-form inline class="toolbar filters" @submit.prevent="loadBatches(1)">
      <el-form-item label="结算日期">
        <el-date-picker v-model="date" type="date" value-format="YYYY-MM-DD" placeholder="全部日期" />
      </el-form-item>
      <el-form-item label="订单号">
        <el-input v-model.trim="orderReference" maxlength="36" clearable placeholder="输入完整订单号" />
      </el-form-item>
      <el-form-item label="复核状态">
        <el-select v-model="batchStatus" clearable placeholder="全部" style="width: 120px">
          <el-option label="有待复核" value="pending" />
          <el-option label="无待复核" value="reviewed" />
        </el-select>
      </el-form-item>
      <el-button native-type="submit" type="primary" :loading="loading">查询</el-button>
    </el-form>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />

    <el-card shadow="never">
      <template #header>对账批次</template>
      <el-table scrollbar-always-on v-loading="loading" :data="batches" border empty-text="暂无对账批次" highlight-current-row row-key="id">
        <el-table-column prop="id" label="批次号" width="78" />
        <el-table-column prop="settlement_date" label="结算日期" width="115" />
        <el-table-column prop="filename" label="导入文件" min-width="150" show-overflow-tooltip />
        <el-table-column prop="line_count" label="明细" width="66" />
        <el-table-column prop="matched_count" label="匹配" width="66" />
        <el-table-column prop="difference_count" label="差异" width="66" />
        <el-table-column prop="pending_count" label="待复核" width="80" />
        <el-table-column label="操作" width="132">
          <template #default="{ row }">
            <el-button link type="primary" @click="openBatch(row)">查看明细</el-button>
            <el-button link type="primary" :disabled="exporting" @click="download(row.id)">导出</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="batchTotal > 20" :current-page="batchPage" :total="batchTotal" :page-size="20"
        layout="prev, pager, next" @current-change="loadBatches"
      />
    </el-card>

    <el-card v-if="selectedBatch" shadow="never">
      <template #header>
        <div class="toolbar">
          <h2>批次 #{{ selectedBatch }} 明细</h2>
          <el-radio-group v-model="lineFilter" aria-label="明细筛选" @change="loadLines(1)">
            <el-radio-button value="all">全部</el-radio-button>
            <el-radio-button value="differences">差异</el-radio-button>
            <el-radio-button value="pending">待复核</el-radio-button>
          </el-radio-group>
          <el-button @click="loadLines(linePage)">刷新明细</el-button>
        </div>
      </template>
      <el-alert v-if="lineError" :title="lineError" type="error" :closable="false" />
      <el-table scrollbar-always-on v-loading="readingLines" :data="lines" border empty-text="暂无符合条件的明细">
        <el-table-column prop="order_reference" label="订单号" min-width="180" show-overflow-tooltip />
        <el-table-column label="导入本金" width="112">
          <template #default="{ row }">{{ euro(row.imported_minor) }}</template>
        </el-table-column>
        <el-table-column label="订单本金" width="112">
          <template #default="{ row }">{{ euro(row.expected_minor) }}</template>
        </el-table-column>
        <el-table-column label="金额差（导入−订单）" width="156">
          <template #default="{ row }">{{ euro(row.expected_minor === null ? null : row.imported_minor - row.expected_minor) }}</template>
        </el-table-column>
        <el-table-column label="比对结果" min-width="105">
          <template #default="{ row }">
            {{ outcome(row.result) }}<span v-if="row.reviewed_at" class="reviewed">已复核</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="88">
          <template #default="{ row }">
            <el-button link type="primary" @click="openLine(row)">{{ row.result !== 'matched' && !row.reviewed_at ? '复核' : '详情' }}</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="lineTotal > 30" :current-page="linePage" :total="lineTotal" :page-size="30"
        layout="prev, pager, next" @current-change="loadLines"
      />
    </el-card>

    <el-dialog v-model="importOpen" title="导入对账 CSV" width="min(500px, 94vw)" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent="upload">
        <el-form-item label="结算日期" required>
          <el-date-picker v-model="importDate" type="date" value-format="YYYY-MM-DD" :disabled="importing" />
        </el-form-item>
        <el-form-item label="本地本金对账模板" required>
          <input type="file" aria-label="选择对账 CSV" accept=".csv,text/csv" :disabled="importing" @change="chooseFile" />
        </el-form-item>
        <p class="help">UTF-8 CSV，最多 5,000 条。金额为 EUR 汇款本金。状态列可选，填写后会一并比对。</p>
        <el-alert v-if="importError" :title="importError" type="error" :closable="false" />
        <div class="actions">
          <el-button :disabled="importing" @click="importOpen = false">取消</el-button>
          <el-button native-type="submit" type="primary" :loading="importing">导入并比对</el-button>
        </div>
      </el-form>
    </el-dialog>

    <el-dialog v-model="reviewOpen" :title="canReview ? '差异复核' : '对账明细'" width="min(540px, 94vw)" :close-on-click-modal="false"
      :close-on-press-escape="!saving" :show-close="!saving">
      <template v-if="selectedLine">
        <el-descriptions :column="1" border>
          <el-descriptions-item label="订单号">{{ selectedLine.order_reference }}</el-descriptions-item>
          <el-descriptions-item label="导入本金">{{ euro(selectedLine.imported_minor) }}</el-descriptions-item>
          <el-descriptions-item label="订单本金">{{ euro(selectedLine.expected_minor) }}</el-descriptions-item>
          <el-descriptions-item label="比对结果">{{ outcome(selectedLine.result) }}</el-descriptions-item>
          <el-descriptions-item label="导入状态">{{ statusLabel(selectedLine.imported_status) }}</el-descriptions-item>
          <el-descriptions-item label="订单状态">{{ statusLabel(selectedLine.expected_status) }}</el-descriptions-item>
          <template v-if="selectedLine.reviewed_at">
            <el-descriptions-item label="复核员工">{{ selectedLine.reviewed_by ?? '—' }}</el-descriptions-item>
            <el-descriptions-item label="复核时间">{{ new Date(selectedLine.reviewed_at).toLocaleString() }}</el-descriptions-item>
            <el-descriptions-item label="复核结论"><span class="note">{{ selectedLine.review_note }}</span></el-descriptions-item>
          </template>
        </el-descriptions>
        <el-form v-if="canReview" label-position="top" class="review-form" @submit.prevent="saveReview">
          <el-form-item label="复核结论" required>
            <el-input v-model="note" type="textarea" :rows="3" maxlength="300" show-word-limit :disabled="saving" />
          </el-form-item>
          <el-alert v-if="reviewError" :title="reviewError" type="error" :closable="false" />
          <div class="actions">
            <el-button :disabled="saving" @click="reviewOpen = false">取消</el-button>
            <el-button native-type="submit" type="primary" :loading="saving" :disabled="!note.trim()">保存复核</el-button>
          </div>
        </el-form>
      </template>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getErrorMessage } from '@/utils/error'
import {
  exportBatch, importBatch, listBatches, listLines, reviewLine,
  type LineFilter, type ReconciliationBatch, type ReconciliationLine,
} from './repository/remitConfigurationRepository'

const batches = ref<ReconciliationBatch[]>([])
const batchPage = ref(1)
const batchTotal = ref(0)
const date = ref('')
const orderReference = ref('')
const batchStatus = ref('')
const loading = ref(false)
const error = ref('')
let batchRequest = 0
const selectedBatch = ref('')
const lineFilter = ref<LineFilter>('all')
const lines = ref<ReconciliationLine[]>([])
const linePage = ref(1)
const lineTotal = ref(0)
const readingLines = ref(false)
const lineError = ref('')
let lineRequest = 0
const importOpen = ref(false)
const importFile = ref<File>()
const importDate = ref('')
const importing = ref(false)
const importError = ref('')
const exporting = ref(false)
const selectedLine = ref<ReconciliationLine>()
const reviewOpen = ref(false)
const saving = ref(false)
const reviewError = ref('')
const note = ref('')
const canReview = computed(() => selectedLine.value && selectedLine.value.result !== 'matched' && !selectedLine.value.reviewed_at)
const currency = new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'EUR' })
const euro = (minor: number | null) => minor === null ? '—' : currency.format(minor / 100)
const outcomes = { matched: '已匹配', missing: '未找到订单', amount: '金额差异', status: '状态差异', amount_status: '金额与状态差异' }
const outcome = (result: keyof typeof outcomes) => outcomes[result]
const statuses: Record<string, string> = {
  pending: '待确认付款', paid: '已付款', paid_out: '已下发', failed: '付款或下发失败',
  refunding: '退款处理中', refunded: '已退款', refund_failed: '退款异常', unknown: '状态待核对',
}
const statusLabel = (status: string | null) => status === null ? '—' : statuses[status] ?? status

async function loadBatches(page: number) {
  const request = ++batchRequest
  loading.value = true
  error.value = ''
  try {
    const data = await listBatches(page, date.value, orderReference.value, batchStatus.value)
    if (request === batchRequest) {
      batches.value = data.list
      batchPage.value = data.page
      batchTotal.value = data.total
    }
  } catch (failure) {
    if (request === batchRequest) {
      error.value = getErrorMessage(failure, '读取对账批次失败')
    }
  } finally {
    if (request === batchRequest) {
      loading.value = false
    }
  }
}

async function loadLines(page: number) {
  const request = ++lineRequest
  readingLines.value = true
  lineError.value = ''
  try {
    const data = await listLines(selectedBatch.value, page, lineFilter.value)
    if (request === lineRequest) {
      lines.value = data.list
      linePage.value = data.page
      lineTotal.value = data.total
    }
  } catch (failure) {
    if (request === lineRequest) {
      lineError.value = getErrorMessage(failure, '读取对账明细失败')
    }
  } finally {
    if (request === lineRequest) {
      readingLines.value = false
    }
  }
}

function openBatch(row: Pick<ReconciliationBatch, 'id'>) {
  selectedBatch.value = row.id
  lineFilter.value = 'all'
  lines.value = []
  lineTotal.value = 0
  void loadLines(1)
}

function openImport() {
  importFile.value = undefined
  importDate.value = date.value || ''
  importError.value = ''
  importOpen.value = true
}

function chooseFile(event: Event) {
  const input = event.target as HTMLInputElement
  importFile.value = input.files?.[0]
  importError.value = ''
}

async function upload() {
  if (importing.value) {
    return
  }
  if (!importFile.value || !importDate.value) {
    importError.value = '请选择结算日期和 CSV 文件'
    return
  }
  importing.value = true
  importError.value = ''
  try {
    const result = await importBatch(importFile.value, importDate.value)
    importOpen.value = false
    date.value = importDate.value
    orderReference.value = ''
    batchStatus.value = ''
    openBatch(result)
    await loadBatches(1)
    ElMessage.success(result.reused ? '已打开原对账批次' : '对账批次已导入')
  } catch (failure) {
    importError.value = getErrorMessage(failure, '导入失败')
  } finally {
    importing.value = false
  }
}

function saveFile(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = name
  link.click()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function downloadTemplate() {
  const sample = 'order_id,amount_eur,status\r\n018f9a47-c349-4cf9-a788-9f51a3fb896a,120.00,paid\r\n'
  saveFile(new Blob([sample], { type: 'text/csv;charset=utf-8' }), 'reconciliation-template.csv')
}

async function download(batch: string) {
  if (exporting.value) {
    return
  }
  exporting.value = true
  error.value = ''
  try {
    saveFile(await exportBatch(batch), 'reconciliation-' + batch + '.csv')
  } catch (failure) {
    error.value = getErrorMessage(failure, '导出失败')
  } finally {
    exporting.value = false
  }
}

function openLine(row: ReconciliationLine) {
  selectedLine.value = row
  note.value = ''
  reviewError.value = ''
  reviewOpen.value = true
}

async function saveReview() {
  if (saving.value || !selectedLine.value || !note.value.trim()) {
    return
  }
  saving.value = true
  reviewError.value = ''
  try {
    await reviewLine(selectedBatch.value, selectedLine.value.id, note.value.trim())
    reviewOpen.value = false
    await Promise.all([loadLines(1), loadBatches(batchPage.value)])
    ElMessage.success('复核已记录')
  } catch (failure) {
    reviewError.value = getErrorMessage(failure, '保存复核失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => void loadBatches(1))
</script>

<style scoped>
.reconciliation-page { display: grid; min-width: 0; gap: 16px; padding: 24px; }
.toolbar, .actions { display: flex; align-items: center; flex-wrap: wrap; gap: 10px 12px; }
.toolbar h1, .toolbar h2 { margin: 0 auto 0 0; }
.toolbar h1 { font-size: 24px; }
.toolbar h2 { font-size: 17px; }
.toolbar :deep(.el-button + .el-button), .filters :deep(.el-form-item), .actions :deep(.el-button + .el-button) { margin: 0; }
.actions { justify-content: flex-end; margin-top: 16px; }
.el-pagination, .review-form { margin-top: 16px; }
.help { color: var(--el-text-color-secondary); }
.note { white-space: pre-wrap; overflow-wrap: anywhere; }
.reviewed { display: block; color: var(--el-color-success); }
</style>
