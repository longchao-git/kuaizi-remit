<template>
  <section class="rules-preview" aria-labelledby="remit-rules-title">
    <h1 id="remit-rules-title">付款与到账规则</h1>
    <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" />
    <el-button v-if="loadError || stale" :loading="busy" @click="loadRules">重新读取规则</el-button>

    <el-card shadow="never">
      <template #header><strong>付款规则</strong></template>
      <el-form label-position="top">
        <el-form-item label="付款渠道">
          <el-select v-model="paymentMethod" class="select">
            <el-option v-for="(label, key) in paymentLabels" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="用户通道费率（%）">
          <el-input-number v-model="payment.rate" :min="0" :max="100" :precision="2" :step="0.01" />
        </el-form-item>
        <el-form-item label="显示付款提示">
          <el-switch v-model="payment.noticeEnabled" aria-label="显示付款提示" />
        </el-form-item>
        <el-form-item label="付款提示（用 {费率} 引用当前费率）">
          <el-input v-model="payment.notice" type="textarea" :rows="2" :maxlength="200" show-word-limit />
        </el-form-item>
        <el-form-item label="基础手续费（EUR）">
          <el-input-number v-model="baseFee" :min="0" :max="10000" :precision="2" />
        </el-form-item>
        <el-form-item label="首单免基础手续费">
          <el-switch v-model="firstOrderFree" />
        </el-form-item>
        <el-form-item v-if="connected" label="EUR → CNY 点差（%）">
          <el-input-number v-model="spread" :min="0" :max="99.99" :precision="2" :step="0.01" />
        </el-form-item>
        <el-button type="primary" :loading="busy" :disabled="quoting || Boolean(loadError) || payment.rate == null || baseFee == null || (connected && spread == null)" @click="savePayment">
          {{ connected ? '保存规则' : '保存演示付款规则' }}
        </el-button>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <template #header><strong>预计到账规则</strong></template>
      <el-form label-position="top">
        <el-form-item label="人民币收款渠道">
          <el-select v-model="payoutMethod" class="select">
            <el-option v-for="(label, key) in payoutLabels" :key="key" :label="label" :value="key" />
          </el-select>
        </el-form-item>
        <el-form-item label="金额分档阈值（EUR）">
          <el-input-number v-model="eta.threshold" :min="0.01" :max="999999999" :precision="2" :step="100" />
        </el-form-item>
        <el-form-item label="低于阈值的预计到账提示">
          <el-input v-model="eta.below" maxlength="80" />
        </el-form-item>
        <el-form-item label="达到或超过阈值的预计到账提示">
          <el-input v-model="eta.atOrAbove" maxlength="80" />
        </el-form-item>
        <template v-if="connected">
          <el-form-item v-for="(band, index) in eta.bands" :key="index" :label="`自定义区间 ${index + 1}`">
            <div class="eta-band">
              <el-input-number v-model="band.from" :min="0" :max="999999999" :precision="2" :step="0.01" aria-label="下限（EUR，含）" placeholder="下限（含）" />
              <el-input-number v-model="band.until" :min="0.01" :max="999999999" :precision="2" :step="0.01" aria-label="上限（EUR，不含）" placeholder="无上限" />
              <el-input v-model.trim="band.label" maxlength="80" placeholder="预计到账提示" aria-label="区间预计到账提示" />
              <el-switch v-model="band.enabled" aria-label="启用该区间" />
              <el-button link type="danger" @click="eta.bands.splice(index, 1)">删除</el-button>
            </div>
          </el-form-item>
          <div class="eta-add">
            <el-button :disabled="eta.bands.length >= 10" @click="eta.bands.push({ from: undefined, until: undefined, label: '', enabled: true })">添加金额区间</el-button>
            <span>自定义区间优先生效，未命中时使用上方的两档提示。</span>
          </div>
        </template>
        <el-button type="primary" :loading="busy" :disabled="quoting || Boolean(loadError) || eta.threshold == null || !eta.below.trim() || !eta.atOrAbove.trim()" @click="saveEta">
          {{ connected ? '保存规则' : '保存演示到账规则' }}
        </el-button>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <template #header><strong>报价预览</strong></template>
      <el-form label-position="top">
        <el-form-item label="汇款金额（EUR）">
          <el-input-number v-model="amount" :min="0.01" :max="999999999" :precision="2" :step="100" />
        </el-form-item>
        <el-checkbox v-model="isFirstOrder">模拟首单</el-checkbox>
        <template v-if="connected">
          <el-form-item label="调整前汇率（EUR → CNY）">
            <el-input-number v-model="testRate" :min="0.0001" :max="100" :precision="4" />
          </el-form-item>
          <el-button :loading="quoting" :disabled="busy || !rulesSaved || Boolean(loadError) || amount == null || testRate == null" @click="calculate">按已保存规则试算</el-button>
        </template>
      </el-form>
      <el-alert v-if="quoteError" :title="quoteError" type="error" :closable="false" />
      <el-alert v-else-if="!preview" :title="quoteHint" type="info" :closable="false" />
      <el-descriptions v-else :column="1" border aria-live="polite">
        <el-descriptions-item label="通道费">{{ preview.fee }}</el-descriptions-item>
        <el-descriptions-item label="基础手续费">{{ preview.baseFee }}</el-descriptions-item>
        <el-descriptions-item label="合计付款">{{ preview.total }}</el-descriptions-item>
        <el-descriptions-item label="付款提示">{{ preview.notice || '未配置' }}</el-descriptions-item>
        <el-descriptions-item label="预计到账">{{ preview.eta }}</el-descriptions-item>
      </el-descriptions>
    </el-card>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { get, HttpError, post, put } from '@/request/http'
import { getErrorMessage } from '@/utils/error'

const { connected = false } = defineProps<{ connected?: boolean }>()
const emit = defineEmits<{ saved: []; record: [action: string, target: string, details: string] }>()

const paymentLabels = { sepa: 'SEPA 银行转账', applePay: 'Apple Pay', card: '借记卡 / 信用卡' }
const payoutLabels = { wechat: '微信收款', bank: '银行卡收款' }
const paymentMethod = ref<keyof typeof paymentLabels>('applePay')
const payoutMethod = ref<keyof typeof payoutLabels>('wechat')
const amount = ref<number | undefined>(1000)
const baseFee = ref<number | undefined>(5.99)
const firstOrderFree = ref(true)
const spread = ref<number | undefined>(0)
const isFirstOrder = ref(false)

const rateNotice = '此付款方式通常另收汇款金额的 {费率}，最终金额以付款前展示为准。'
const paymentRules = reactive<Record<keyof typeof paymentLabels, { rate: number | undefined; notice: string; noticeEnabled: boolean }>>({
  sepa: { rate: 0, notice: '此付款方式免通道费。', noticeEnabled: true },
  applePay: { rate: 0.8, notice: rateNotice, noticeEnabled: true },
  card: { rate: 0.8, notice: rateNotice, noticeEnabled: true }
})
type EtaBand = { from: number | undefined; until: number | undefined; label: string; enabled: boolean }
type EtaApiBand = { from_minor: number; until_minor: number | null; label: string; enabled: boolean }
const etaRules = reactive<Record<keyof typeof payoutLabels, {
  threshold: number | undefined
  below: string
  atOrAbove: string
  bands: EtaBand[]
}>>({
  wechat: { threshold: 2000, below: '预计 2–10 分钟到账', atOrAbove: '预计 5–15 分钟到账', bands: [] },
  bank: { threshold: 2000, below: '预计 5–15 分钟到账', atOrAbove: '预计 15–30 分钟到账', bands: [] }
})

const payment = computed(() => paymentRules[paymentMethod.value])
const eta = computed(() => etaRules[payoutMethod.value])
const currency = new Intl.NumberFormat('en-IE', { style: 'currency', currency: 'EUR' })
const endpoint = '/admin/remittance'
const testRate = ref<number | undefined>(8.054)
const busy = ref(false)
const loadError = ref('')
const rulesSaved = ref(false)
const quoting = ref(false)
const quoteError = ref('')
let quoteInputVersion = 0
const quoteHint = computed(() => {
  if (!connected) return '请填写金额、费率、分档阈值及当前分档的到账提示'
  if (quoting.value) return '正在试算'
  return rulesSaved.value ? '输入金额和汇率后发起试算' : '保存规则后可发起试算'
})
const serverPreview = ref<{ fee: string; baseFee: string; total: string; notice: string; eta: string } | null>(null)

type Rules = {
  revision_id?: string
  base_fee_minor: number
  first_order_free: boolean
  spread_basis_points?: number
  payment: Record<keyof typeof paymentLabels, { basis_points: number; notice?: string; notice_enabled?: boolean }>
  eta: Record<keyof typeof payoutLabels, { threshold_minor: number; below: string; at_or_above: string; bands?: EtaApiBand[] }>
}

const revision = ref('0')
const stale = ref(false)
onMounted(loadRules)

async function loadRules() {
  if (!connected || busy.value) return
  busy.value = true
  try {
    const rules = await get<Rules>(`${endpoint}/rules`)
    revision.value = rules.revision_id ?? '0'
    stale.value = false
    baseFee.value = rules.base_fee_minor / 100
    firstOrderFree.value = rules.first_order_free
    spread.value = (rules.spread_basis_points ?? 0) / 100
    for (const key of ['sepa', 'applePay', 'card'] as const) {
      const rule = rules.payment[key]
      paymentRules[key] = { rate: rule.basis_points / 100, notice: rule.notice ?? '', noticeEnabled: rule.notice_enabled ?? true }
    }
    for (const key of ['wechat', 'bank'] as const) {
      const rule = rules.eta[key]
      etaRules[key] = {
        threshold: rule.threshold_minor / 100,
        below: rule.below,
        atOrAbove: rule.at_or_above,
        bands: (rule.bands ?? []).map(band => ({
          from: band.from_minor / 100,
          until: band.until_minor === null ? undefined : band.until_minor / 100,
          label: band.label,
          enabled: band.enabled
        }))
      }
    }
    rulesSaved.value = true
    loadError.value = ''
  } catch (error) {
    if (!(error instanceof HttpError && error.code === 409)) loadError.value = getErrorMessage(error, '读取汇款规则失败')
  } finally {
    busy.value = false
  }
}

watch([amount, testRate, paymentMethod, payoutMethod, isFirstOrder], () => {
  quoteInputVersion++
  serverPreview.value = null
  quoteError.value = ''
})

async function saveRules() {
  if (busy.value || quoting.value || loadError.value || stale.value || baseFee.value == null || spread.value == null) return
  const { sepa, applePay, card } = paymentRules
  const { wechat, bank } = etaRules
  if (sepa.rate == null || applePay.rate == null || card.rate == null || wechat.threshold == null || bank.threshold == null) {
    ElMessage.warning('请补齐各渠道的费率和到账分档')
    return
  }
  const bandsFor = (bands: EtaBand[]): EtaApiBand[] => bands.map(band => {
    if (band.from == null || !band.label.trim()) throw new Error('请填写所有预计到账区间的下限和提示文案')
    return {
      from_minor: Math.round(band.from * 100),
      until_minor: band.until == null ? null : Math.round(band.until * 100),
      label: band.label,
      enabled: band.enabled
    }
  })
  let wechatBands: EtaApiBand[]
  let bankBands: EtaApiBand[]
  try {
    wechatBands = bandsFor(wechat.bands)
    bankBands = bandsFor(bank.bands)
  } catch (error) {
    ElMessage.warning(getErrorMessage(error, '请检查预计到账区间'))
    return
  }

  const rules: Rules = {
    revision_id: revision.value,
    base_fee_minor: Math.round(baseFee.value * 100),
    first_order_free: firstOrderFree.value,
    spread_basis_points: Math.round(spread.value * 100),
    payment: {
      sepa: { basis_points: Math.round(sepa.rate * 100), notice: sepa.notice, notice_enabled: sepa.noticeEnabled },
      applePay: { basis_points: Math.round(applePay.rate * 100), notice: applePay.notice, notice_enabled: applePay.noticeEnabled },
      card: { basis_points: Math.round(card.rate * 100), notice: card.notice, notice_enabled: card.noticeEnabled }
    },
    eta: {
      wechat: { threshold_minor: Math.round(wechat.threshold * 100), below: wechat.below, at_or_above: wechat.atOrAbove, bands: wechatBands },
      bank: { threshold_minor: Math.round(bank.threshold * 100), below: bank.below, at_or_above: bank.atOrAbove, bands: bankBands }
    }
  }

  busy.value = true
  try {
    const saved = await put<Rules>(`${endpoint}/rules`, rules)
    revision.value = saved.revision_id ?? revision.value
    rulesSaved.value = true
    serverPreview.value = null
    quoteError.value = ''
    ElMessage.success('已保存汇款规则')
    emit('saved')
  } catch (error) {
    if (error instanceof HttpError && error.status === 409) {
      stale.value = true
      loadError.value = error.message
    }
    ElMessage.error(getErrorMessage(error, '保存规则失败'))
  } finally {
    busy.value = false
  }
}

async function calculate() {
  if (busy.value || quoting.value || !rulesSaved.value || loadError.value || amount.value == null || testRate.value == null) return
  const input = {
    principal_minor: Math.round(amount.value * 100),
    rate_ten_thousandths: Math.round(testRate.value * 10000),
    payment_method: paymentMethod.value,
    payout_method: payoutMethod.value,
    simulate_first_order: isFirstOrder.value
  }
  const version = quoteInputVersion
  serverPreview.value = null
  quoteError.value = ''
  quoting.value = true
  try {
    const quote = await post<{ channel_fee_minor: number; base_fee_minor: number; discount_minor: number; total_payable_minor: number; payment_notice: string; eta: string }>(`${endpoint}/quote-preview`, input)
    if (version !== quoteInputVersion) return
    serverPreview.value = {
      fee: currency.format(quote.channel_fee_minor / 100),
      baseFee: currency.format((quote.base_fee_minor - quote.discount_minor) / 100),
      total: currency.format(quote.total_payable_minor / 100),
      notice: quote.payment_notice,
      eta: quote.eta
    }
  } catch (error) {
    if (version === quoteInputVersion) quoteError.value = getErrorMessage(error, '报价试算失败，请重试')
  } finally {
    quoting.value = false
  }
}

const preview = computed(() => {
  if (connected) return serverPreview.value
  const { rate, notice, noticeEnabled } = payment.value
  const { threshold, below, atOrAbove } = eta.value
  const principal = amount.value
  if (principal == null || rate == null || threshold == null || baseFee.value == null) {
    return null
  }
  const etaText = (principal < threshold ? below : atOrAbove).trim()
  if (!etaText) {
    return null
  }
  const cents = Math.round(principal * 100)
  const basisPoints = Math.round(rate * 100)
  const channelCents = Math.round(cents * basisPoints / 10000)
  const baseCents = firstOrderFree.value && isFirstOrder.value ? 0 : Math.round(baseFee.value * 100)
  return {
    fee: currency.format(channelCents / 100),
    baseFee: currency.format(baseCents / 100),
    total: currency.format((cents + baseCents + channelCents) / 100),
    notice: noticeEnabled ? notice.trim().replaceAll('{费率}', `${rate}%`) : '',
    eta: etaText
  }
})

function savePayment() {
  if (connected) return saveRules()
  if (payment.value.rate == null || baseFee.value == null) return
  emit('record', '保存付款规则', paymentLabels[paymentMethod.value], `通道费率 ${payment.value.rate}%，基础手续费 ${currency.format(baseFee.value)}`)
  ElMessage.success('已保存到当前演示')
}

function saveEta() {
  if (connected) return saveRules()
  const { threshold, below, atOrAbove } = eta.value
  if (threshold == null || !below.trim() || !atOrAbove.trim()) return
  emit('record', '保存到账规则', payoutLabels[payoutMethod.value], `金额分档 ${currency.format(threshold)}。 ${below} / ${atOrAbove}`)
  ElMessage.success('已保存到当前演示')
}
</script>

<style scoped>
.rules-preview {
  display: grid;
  gap: 16px;
  padding: 24px 28px;
  max-width: 880px;
}

.rules-preview h1 {
  margin: 0;
  font-size: 24px;
}

.select {
  max-width: 260px;
}

.eta-band { width: 100%; display: grid; grid-template-columns: 138px 138px minmax(140px, 1fr) auto auto; gap: 8px; align-items: center; }
.eta-band .el-input-number { width: 100%; }
.eta-add { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 16px; color: var(--el-text-color-secondary); }
@media (max-width: 780px) {
  .eta-band { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .eta-band > .el-input { grid-column: 1 / -1; }
}
</style>
