<template>
  <section class="records-page">
    <h1>报价记录</h1>
    <el-form inline class="filters" @submit.prevent="search">
      <el-form-item label="试算编号"><el-input v-model.trim="reference" maxlength="36" clearable /></el-form-item>
      <el-form-item label="会员编号"><el-input v-model.trim="member" maxlength="18" clearable /></el-form-item>
      <el-button native-type="submit" type="primary" :loading="loading">查询</el-button>
      <el-button :disabled="loading" @click="reset">重置</el-button>
    </el-form>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows" :empty-text="error ? '读取失败' : '暂无报价记录'">
      <el-table-column type="expand">
        <template #default="{ row }">
          <el-descriptions :column="2" border class="snapshot">
            <el-descriptions-item label="付款渠道">{{ fundingLabels[row.payment_method] }}</el-descriptions-item>
            <el-descriptions-item label="收款渠道">{{ payoutLabels[row.payout_method] }}</el-descriptions-item>
            <el-descriptions-item label="基础手续费">{{ euro(row.base_fee_minor) }}</el-descriptions-item>
            <el-descriptions-item label="优惠减免">{{ euro(row.discount_minor) }}</el-descriptions-item>
            <el-descriptions-item label="通道费">{{ euro(row.channel_fee_minor) }}（{{ row.channel_fee_basis_points / 100 }}%）</el-descriptions-item>
            <el-descriptions-item label="预计支付">{{ euro(row.total_payable_minor) }}</el-descriptions-item>
            <el-descriptions-item label="预计到账时间">{{ row.eta }}</el-descriptions-item>
            <el-descriptions-item label="付款提示">{{ row.payment_notice || '—' }}</el-descriptions-item>
            <el-descriptions-item label="参考汇率">{{ row.source_rate_ten_thousandths == null ? '—' : (row.source_rate_ten_thousandths / 10000).toFixed(4) }}</el-descriptions-item>
            <el-descriptions-item label="点差">{{ row.spread_basis_points == null ? '—' : row.spread_basis_points / 100 + '%' }}</el-descriptions-item>
          </el-descriptions>
          <el-button class="margin-action" type="primary" link @click="openMargin(row.record_no)">预估毛利</el-button>
        </template>
      </el-table-column>
      <el-table-column prop="record_no" label="试算编号" min-width="270" show-overflow-tooltip />
      <el-table-column prop="member_id" label="会员" width="85" />
      <el-table-column label="金额" min-width="120"><template #default="{ row }">{{ euro(row.principal_minor) }}</template></el-table-column>
      <el-table-column label="预计到账" min-width="120"><template #default="{ row }">{{ yuan.format(row.payout_minor / 100) }}</template></el-table-column>
      <el-table-column label="试算汇率" min-width="100"><template #default="{ row }">{{ (row.rate_ten_thousandths / 10000).toFixed(4) }}</template></el-table-column>
      <el-table-column label="规则版本" min-width="100"><template #default="{ row }">{{ row.rule_revision_id ?? '—' }}</template></el-table-column>
      <el-table-column label="试算时间" min-width="175"><template #default="{ row }">{{ new Date(row.created_at).toLocaleString() }}</template></el-table-column>
    </el-table>
    <el-pagination v-if="total > 20" :current-page="page" :page-size="20" :total="total" :disabled="loading" layout="prev, pager, next" @current-change="load" />
    <el-dialog v-model="marginOpen" title="毛利预估" width="min(500px, 94vw)" :before-close="closeMargin">
      <el-form label-position="top" @submit.prevent="calculateMargin">
        <el-form-item label="试算编号">
          <span>{{ marginReference }}</span>
        </el-form-item>
        <el-form-item label="点差收入（EUR）">
          <el-input-number v-model="fxIncome" :min="-999999999" :max="999999999" :step="0.01" :precision="2" :disabled="calculating" />
        </el-form-item>
        <el-form-item label="渠道及结算成本（EUR）">
          <el-input-number v-model="cost" :min="0" :max="999999999" :step="0.01" :precision="2" :disabled="calculating" />
        </el-form-item>
        <el-button type="primary" native-type="submit" :loading="calculating" :disabled="fxIncome == null || cost == null">计算毛利</el-button>
      </el-form>
      <el-alert v-if="marginError" :title="marginError" type="error" :closable="false" />
      <el-descriptions v-if="margin" :column="1" border class="margin-result">
        <el-descriptions-item label="手续费收入">{{ euro(margin.fee_income_minor) }}</el-descriptions-item>
        <el-descriptions-item label="预估毛利">{{ euro(margin.gross_margin_minor) }}</el-descriptions-item>
        <el-descriptions-item label="占汇款本金">{{ margin.gross_margin_percent_of_principal }}%</el-descriptions-item>
      </el-descriptions>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { get, post } from '@/request/http'
import { getErrorMessage } from '@/utils/error'
import type { Page } from './repository/remitOperationsRepository'

interface QuoteRecord {
  record_no: string
  member_id: string
  created_at: string
  principal_minor: number
  payout_minor: number
  rate_ten_thousandths: number
  rule_revision_id: string | null
}

interface Margin {
  fee_income_minor: number
  gross_margin_minor: number
  gross_margin_percent_of_principal: number
}

const fundingLabels: Record<string, string> = { sepa: 'SEPA', applePay: 'Apple Pay', card: '银行卡' }
const payoutLabels: Record<string, string> = { wechat: '微信', bank: '银行卡' }
const currency = new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' })
const yuan = new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'CNY' })
const euro = (cents: number) => currency.format(cents / 100)
const page = ref(1)
const total = ref(0)
const reference = ref('')
const member = ref('')
const error = ref('')
const loading = ref(false)
const rows = ref<QuoteRecord[]>([])
const marginOpen = ref(false)
const marginReference = ref('')
const fxIncome = ref<number>()
const cost = ref<number>()
const calculating = ref(false)
const marginError = ref('')
const margin = ref<Margin>()
let filters = { record_no: '', member_id: '' }

watch([fxIncome, cost], () => {
  margin.value = undefined
  marginError.value = ''
})

async function load(next: number) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const result = await get<Page<QuoteRecord>>('/admin/remittance/quote-records', {
      page: next, record_no: filters.record_no || undefined, member_id: filters.member_id || undefined
    })
    rows.value = result.list
    page.value = result.page
    total.value = result.total
  } catch (reason) {
    rows.value = []
    total.value = 0
    error.value = getErrorMessage(reason, '读取报价记录失败')
  } finally {
    loading.value = false
  }
}

function search() {
  if (loading.value) return
  filters = { record_no: reference.value, member_id: member.value }
  void load(1)
}

function reset() {
  reference.value = ''
  member.value = ''
  search()
}

function openMargin(reference: string) {
  marginReference.value = reference
  fxIncome.value = undefined
  cost.value = undefined
  margin.value = undefined
  marginError.value = ''
  marginOpen.value = true
}

function closeMargin(done: () => void) {
  if (!calculating.value) done()
}

async function calculateMargin() {
  if (calculating.value || fxIncome.value == null || cost.value == null) return
  calculating.value = true
  marginError.value = ''
  try {
    margin.value = await post<Margin>('/admin/remittance/margin-preview', {
      record_no: marginReference.value,
      fx_income_minor: Math.round(fxIncome.value * 100),
      cost_minor: Math.round(cost.value * 100)
    })
  } catch (reason) {
    marginError.value = getErrorMessage(reason, '毛利预估失败')
  } finally {
    calculating.value = false
  }
}

onMounted(() => void load(1))
</script>

<style scoped>
.records-page { display: grid; gap: 16px; padding: 24px 28px; }
.records-page h1 { margin: 0; }
.filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
.filters :deep(.el-form-item), .filters .el-button { margin: 0; }
.snapshot { margin: 12px 24px; }
.margin-action { margin: 0 24px 12px; }
.margin-result { margin-top: 16px; }
</style>
