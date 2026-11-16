<template>
  <ListPageLayout v-if="view">
    <template #toolbar><h1 class="workbench-title">{{ title }}</h1></template>

    <template v-if="view === 'dashboard'">
      <el-row :gutter="12">
        <el-col v-for="metric in metrics" :key="metric.label" :xs="12" :sm="6">
          <el-card shadow="never">
            <el-statistic :title="metric.label" :value="metric.count" />
          </el-card>
        </el-col>
      </el-row>
      <h2 class="workbench-section-title">最近汇款</h2>
      <el-table :data="previewOrders" border>
        <el-table-column prop="id" label="订单号" />
        <el-table-column prop="eur" label="汇款额" />
        <el-table-column prop="status" label="状态" />
      </el-table>
    </template>

    <el-card v-else-if="view === 'profit'" shadow="never">
      <el-form label-position="top">
        <el-form-item label="汇款本金（EUR）">
          <el-input-number v-model="principal" :min="0.01" :max="1000000" :precision="2" />
        </el-form-item>
        <el-form-item label="手续费收入（EUR）">
          <el-input-number v-model="feeIncome" :min="0" :max="1000000" :precision="2" />
        </el-form-item>
        <el-form-item label="点差收入（EUR）">
          <el-input-number v-model="spreadIncome" :min="0" :max="1000000" :precision="2" />
        </el-form-item>
        <el-form-item label="渠道及结算成本（EUR）">
          <el-input-number v-model="cost" :min="0" :max="1000000" :precision="2" />
        </el-form-item>
      </el-form>
      <el-descriptions :column="1" border v-if="profit !== undefined">
        <el-descriptions-item label="预估毛利">{{ money(profit) }}</el-descriptions-item>
        <el-descriptions-item label="毛利率">{{ (profit / (principal || 1)).toLocaleString('zh-CN', { style: 'percent', maximumFractionDigits: 2 }) }}</el-descriptions-item>
      </el-descriptions>
      <el-alert v-else title="请填写汇款本金、收入和成本" type="info" :closable="false" />
    </el-card>

    <template v-else-if="view === 'finance'">
      <el-card shadow="never">
        <label class="file-field">
          导入对账 CSV
          <input type="file" accept=".csv,text/csv" :disabled="importing" @change="importSettlement" />
        </label>
        <p class="format-tip">表头使用 order_id,amount_eur，金额单位为欧元。</p>
        <el-alert v-if="importError" :title="importError" type="error" :closable="false" />
      </el-card>
      <el-table :data="settlement" border empty-text="暂无导入明细">
        <el-table-column prop="order" label="订单号" />
        <el-table-column label="导入金额">
          <template #default="{ row }">{{ money(row.imported / 100) }}</template>
        </el-table-column>
        <el-table-column label="订单本金">
          <template #default="{ row }">{{ row.expected === undefined ? '—' : money(row.expected / 100) }}</template>
        </el-table-column>
        <el-table-column prop="status" label="匹配结果" />
        <el-table-column label="操作">
          <template #default="{ row }">
            <el-button v-if="row.status !== '已匹配'" link type="primary" @click="reviewSettlement(row)">
              {{ row.reviewNote ? '查看复核' : '记录复核' }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </template>

    <el-table v-else-if="view === 'audit'" :data="auditRows" border empty-text="暂无演示操作记录">
      <el-table-column prop="time" label="时间" min-width="160" />
      <el-table-column prop="action" label="操作" min-width="130" />
      <el-table-column prop="target" label="对象" min-width="100" />
      <el-table-column prop="details" label="详情" min-width="200" />
    </el-table>

    <template v-else-if="view === 'roles'">
      <el-table :data="roles" border>
        <el-table-column prop="role" label="角色" />
        <el-table-column prop="scope" label="操作范围" min-width="260" />
      </el-table>
      <div class="workbench-actions">
        <el-button type="primary" @click="router.push('/system/remittance/staff')">打开正式角色管理</el-button>
      </div>
    </template>

    <template v-else-if="table">
      <div class="workbench-actions">
        <el-input v-model="query" placeholder="搜索记录" clearable />
        <el-button v-if="table.editable" type="primary" @click="openEditor()">{{ table.createLabel }}</el-button>
      </div>
      <el-table :data="filteredRows" border empty-text="暂无匹配记录">
        <el-table-column
          v-for="field in table.fields"
          :key="field.key"
          :prop="field.key"
          :label="field.label"
          min-width="120"
          show-overflow-tooltip
        />
        <el-table-column v-if="table.editable || view === 'coupons'" label="操作" width="140">
          <template #default="{ row }">
            <el-button v-if="view === 'coupons' && row.status === '未使用'" link type="danger" @click="revoke(row)">撤销</el-button>
            <el-button v-else-if="view !== 'coupons'" link type="primary" @click="openEditor(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
      <template v-if="view === 'push'">
        <h2 class="workbench-section-title">发送记录</h2>
        <el-empty description="暂无发送记录" :image-size="64" />
      </template>
    </template>
  </ListPageLayout>

  <el-dialog v-if="table" v-model="editorOpen" :title="editingId ? '编辑' + title : table.createLabel" width="min(560px, 95vw)">
    <el-form label-position="top" @submit.prevent="saveEditor">
      <el-form-item v-for="field in editableFields" :key="field.key" :label="field.label">
        <el-select v-if="field.key === 'template'" v-model="draft[field.key]">
          <el-option v-for="item in availableTemplates" :key="item.id" :label="item.name" :value="item.id" />
        </el-select>
        <el-select v-else-if="field.options" v-model="draft[field.key]">
          <el-option v-for="value in field.options" :key="value" :value="value" :label="value" />
        </el-select>
        <el-input v-else v-model="draft[field.key]" :maxlength="160" />
      </el-form-item>
    </el-form>
    <el-alert v-if="editorError" :title="editorError" type="error" :closable="false" />
    <template #footer>
      <el-button @click="editorOpen = false">取消</el-button>
      <el-button type="primary" @click="saveEditor">保存演示记录</el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useRouter } from 'vue-router'
import ListPageLayout from '@/components/list-page-layout/ListPageLayout.vue'
import { previewCustomers, previewOrders } from './remitPreviewData'
import { parseSettlement, workbenchMenus } from './remitWorkbenchData'
import type { AuditRow, WorkbenchKey } from './remitWorkbenchData'
import { workbenchTables, initialWorkbenchRows } from './remitWorkbenchTables'
import type { WorkbenchRow, WorkbenchTableKey } from './remitWorkbenchTables'

const props = defineProps<{ view?: WorkbenchKey; auditRows: AuditRow[] }>()
const emit = defineEmits<{ record: [action: string, target: string, details: string] }>()
const router = useRouter()
const roles = [
  { role: '运营', scope: '汇款查询、规则配置、优惠券与活动管理' },
  { role: '客服', scope: '订单和认证状态查询、客服回复' },
  { role: '财务', scope: '对账与结算、退款进度查询' },
  { role: '管理员', scope: '角色授权和操作审计' }
]
const title = computed(() => workbenchMenus.find(item => item.key === props.view)?.label ?? '')
const tableKey = computed(() => props.view && props.view in workbenchTables ? props.view as WorkbenchTableKey : undefined)
const table = computed(() => tableKey.value ? workbenchTables[tableKey.value] : undefined)
const rows = reactive(structuredClone(initialWorkbenchRows))
const availableTemplates = computed(() => rows.templates.filter(item => item.status === '启用'))
const metrics = [
  { label: '汇款订单', count: previewOrders.length },
  { label: '已到账', count: previewOrders.filter(row => row.payout === '已到账').length },
  { label: '待补件', count: previewOrders.filter(row => row.status === '待补件').length },
  { label: '已退款', count: previewOrders.filter(row => row.refund === '已退款').length }
]
const currency = new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' })
const money = (value: number) => currency.format(value)
const principal = ref<number | undefined>(1000)
const feeIncome = ref<number | undefined>(5.99)
const spreadIncome = ref<number | undefined>(2)
const cost = ref<number | undefined>(4)
const profit = computed(() => {
  if (!principal.value || feeIncome.value == null || spreadIncome.value == null || cost.value == null) return
  const cents = Math.round(feeIncome.value * 100) + Math.round(spreadIncome.value * 100) - Math.round(cost.value * 100)
  return cents / 100
})
const query = ref('')
const editorOpen = ref(false)
const editorError = ref('')
const editingId = ref('')
const draft = ref<Record<string, string>>({})
const editableFields = computed(() => table.value?.fields.filter(field =>
  field.key !== 'id' && !field.readonly && (field.key !== 'code' || draft.value.delivery !== '直接发券')
) ?? [])
const filteredRows = computed(() => {
  if (!tableKey.value) return []
  const keyword = query.value.trim().toLowerCase()
  return rows[tableKey.value].filter(row => Object.values(row).some(value => value.toLowerCase().includes(keyword)))
})
watch(() => props.view, () => {
  query.value = ''
  editorOpen.value = false
})

function openEditor(row?: WorkbenchRow) {
  editingId.value = row?.id ?? ''
  draft.value = {}
  draft.value = Object.fromEntries(editableFields.value.map(field => [
    field.key,
    row?.[field.key] ?? field.options?.[0] ?? ''
  ]))
  editorError.value = ''
  editorOpen.value = true
}

function saveEditor() {
  const key = tableKey.value
  if (!key || !table.value?.editable) return
  const values = Object.fromEntries(editableFields.value.map(field => [field.key, draft.value[field.key]?.trim() ?? '']))
  const missing = editableFields.value.find(field => !values[field.key])
  if (missing) {
    editorError.value = '请填写' + missing.label
    return
  }
  const invalid = editableFields.value.find(field => field.validate && !field.validate(values[field.key]))
  if (invalid) {
    editorError.value = invalid.label + '格式或范围无效'
    return
  }
  if (key === 'coupons') {
    const template = availableTemplates.value.find(item => item.id === values.template)
    if (!template) {
      editorError.value = '请选择启用的券模板'
      return
    }
    if (values.delivery === '优惠码' && rows.coupons.some(row => row.code.toLowerCase() === values.code.toLowerCase())) {
      editorError.value = '兑换码已存在'
      return
    }
    if (values.delivery === '直接发券' && !previewCustomers.some(user => user.id === values.audience)) {
      editorError.value = '直接发券需要输入有效的用户编号'
      return
    }
    values.template = template.name
    values.code ||= '—'
  }
  const existing = rows[key].find(row => row.id === editingId.value)
  const record: WorkbenchRow = { ...existing, ...values, id: existing?.id ?? `${key}-${rows[key].length + 1}` }
  if (key === 'coupons') record.status = '未使用'
  if (existing) Object.assign(existing, record)
  else rows[key].push(record)
  emit('record', existing ? '编辑' + title.value : table.value.createLabel, record.id, Object.values(values).join(' · '))
  editorOpen.value = false
  ElMessage.success('演示记录已保存')
}

async function revoke(row: WorkbenchRow) {
  try {
    await ElMessageBox.confirm('撤销后该优惠不可使用。', '撤销演示优惠', { type: 'warning' })
  } catch {
    return
  }
  if (row.status !== '未使用') return
  row.status = '已撤销'
  emit('record', '撤销优惠', row.code === '—' ? row.id : row.code, '未使用 → 已撤销')
}

type SettlementRow = ReturnType<typeof parseSettlement>[number] & { reviewNote?: string }
const settlement = ref<SettlementRow[]>([])
const importing = ref(false)
const importError = ref('')
const expectedAmounts = new Map(previewOrders.map(row => [row.id, Math.round(Number(row.eur.replace(/[^\d.]/g, '')) * 100)]))

async function importSettlement(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  input.value = ''
  importError.value = ''
  importing.value = true
  try {
    if (file.size > 1024 * 1024) throw new Error('CSV 文件不能超过 1 MB')
    settlement.value = parseSettlement(await file.text(), expectedAmounts)
    emit('record', '导入演示对账', file.name, `${settlement.value.length} 条明细`)
  } catch (error) {
    importError.value = error instanceof Error ? error.message : '导入失败'
  } finally {
    importing.value = false
  }
}

async function reviewSettlement(row: SettlementRow) {
  try {
    const { value } = await ElMessageBox.prompt('填写本笔差异的复核说明。', row.order, {
      inputValue: row.reviewNote ?? '',
      inputValidator: value => Boolean(value?.trim() && value.trim().length <= 200) || '请填写 1–200 字',
      confirmButtonText: '保存演示复核'
    })
    row.reviewNote = value.trim()
    emit('record', '复核对账差异', row.order, row.reviewNote)
  } catch {
    return
  }
}
</script>

<style scoped>
.workbench-title { margin: 0; font-size: 24px; }
.workbench-section-title { margin: 20px 0 12px; font-size: 18px; }
.workbench-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-bottom: 16px; }
.workbench-actions .el-input { max-width: 260px; }
.workbench-actions :deep(.el-input__wrapper),
.workbench-actions > .el-button {
  box-sizing: border-box;
  height: var(--omc-control-height);
}
.file-field { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.format-tip { color: #526078; }
</style>
