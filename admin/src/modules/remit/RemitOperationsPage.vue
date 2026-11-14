<template>
  <section v-loading="loading" class="operations-page">
    <header class="toolbar">
      <h1>运营配置</h1>
      <el-button :disabled="saving" @click="load">刷新</el-button>
      <el-button type="primary" :loading="saving" :disabled="!settings || loading" @click="save">保存运营配置</el-button>
    </header>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <router-link v-if="!settings && !loading" to="/system/remittance/rules">前往付款与到账规则</router-link>
    <template v-if="settings">
      <div class="configuration-grid">
        <el-card shadow="never">
          <template #header>市场与 App 展示</template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="首发市场">西班牙</el-descriptions-item>
            <el-descriptions-item label="预计到账">按金额与人民币收款渠道匹配</el-descriptions-item>
          </el-descriptions>
          <el-form label-position="top" class="announcement">
            <el-form-item label="App 首页公告">
              <el-switch v-model="draft.announcement.enabled" :disabled="saving" aria-label="显示首页公告" />
            </el-form-item>
            <el-form-item label="公告文案">
              <el-input
                v-model="draft.announcement.text" type="textarea" maxlength="200" :rows="3"
                show-word-limit :disabled="saving"
              />
            </el-form-item>
          </el-form>
          <div class="preview" aria-label="公告预览">
            {{ draft.announcement.enabled ? (draft.announcement.text || '请填写公告文案') : '首页公告已关闭' }}
          </div>
        </el-card>

        <el-card shadow="never">
          <template #header>付款提示</template>
          <el-form label-position="top" @submit.prevent="save">
            <el-form-item label="付款渠道">
              <el-select v-model="channel" :disabled="saving">
                <el-option v-for="(label, key) in channels" :key="key" :value="key" :label="label" />
              </el-select>
            </el-form-item>
            <el-form-item label="生效通道费率"><strong>{{ feeRate }}</strong></el-form-item>
            <el-form-item label="显示付款提示">
              <el-switch v-model="draft.payment[channel].notice_enabled" :disabled="saving" aria-label="显示付款提示" />
            </el-form-item>
            <el-form-item label="付款提示文案">
              <el-input
                v-model="draft.payment[channel].notice" type="textarea" maxlength="200" :rows="3"
                placeholder="用 {费率} 引用该渠道的生效费率" show-word-limit :disabled="saving"
              />
            </el-form-item>
          </el-form>
          <div class="preview" aria-label="付款提示预览">{{ paymentPreview }}</div>
        </el-card>
      </div>
      <div class="related">
        <router-link to="/system/remittance/rules">付款与到账规则</router-link>
        <router-link v-if="hasCoupons" to="/system/remittance/coupons">优惠券模板与管理</router-link>
        <span>规则版本 {{ settings.revision_id === '0' ? '初始版本' : settings.revision_id }}</span>
        <span>基础手续费 {{ euro(settings.base_fee_minor) }}</span>
        <span>首单免基础手续费{{ settings.first_order_free ? '已启用' : '已关闭' }}</span>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { hasPermission } from '@/utils/permission'
import { getErrorMessage } from '@/utils/error'
import {
  readOperations, saveOperations, type OperationsInput, type OperationsSettings, type PaymentChannel,
} from './repository/remitConfigurationRepository'

const channels: Record<PaymentChannel, string> = { sepa: 'SEPA 银行转账', applePay: 'Apple Pay', card: '借记卡／信用卡' }
const keys = Object.keys(channels) as PaymentChannel[]
const channel = ref<PaymentChannel>('applePay')
const settings = ref<OperationsSettings>()
const error = ref('')
const loading = ref(false)
const saving = ref(false)
const hasCoupons = computed(() => hasPermission('remit:coupons:manage'))
const draft = reactive({
  announcement: { enabled: false, text: '' },
  payment: {
    sepa: { notice_enabled: false, notice: '' },
    applePay: { notice_enabled: false, notice: '' },
    card: { notice_enabled: false, notice: '' },
  },
})
const feeRate = computed(() => String((settings.value?.payment[channel.value].basis_points ?? 0) / 100) + '%')
const currency = new Intl.NumberFormat('zh-CN', { style: 'currency', currency: 'EUR' })
const euro = (minor: number) => currency.format(minor / 100)
const paymentPreview = computed(() => {
  const payment = draft.payment[channel.value]
  if (!payment.notice_enabled) {
    return '该渠道付款提示已关闭'
  }
  return payment.notice.replaceAll('{费率}', feeRate.value) || '请填写付款提示文案'
})

function hydrate(result: OperationsSettings) {
  settings.value = result
  draft.announcement.enabled = result.announcement?.enabled ?? false
  draft.announcement.text = result.announcement?.text ?? ''
  for (const key of keys) {
    const payment = result.payment[key]
    draft.payment[key] = { notice: payment.notice ?? '', notice_enabled: payment.notice_enabled ?? Boolean(payment.notice) }
  }
}

async function load() {
  if (loading.value || saving.value) {
    return
  }
  loading.value = true
  error.value = ''
  try {
    hydrate(await readOperations())
  } catch (failure) {
    error.value = getErrorMessage(failure, '读取运营配置失败')
  } finally {
    loading.value = false
  }
}

async function save() {
  if (!settings.value || loading.value || saving.value) {
    return
  }
  error.value = ''
  const input: OperationsInput = {
    revision_id: settings.value.revision_id,
    announcement: { enabled: draft.announcement.enabled, text: draft.announcement.text.trim() || null },
    payment: { sepa: { ...draft.payment.sepa }, applePay: { ...draft.payment.applePay }, card: { ...draft.payment.card } },
  }
  if (input.announcement.enabled && !input.announcement.text) {
    error.value = '请填写首页公告文案'
    return
  }
  for (const key of keys) {
    const payment = input.payment[key]
    payment.notice = payment.notice?.trim() || null
    if (payment.notice_enabled && !payment.notice) {
      channel.value = key
      error.value = '请填写' + channels[key] + '的付款提示文案'
      return
    }
  }
  saving.value = true
  try {
    hydrate(await saveOperations(input))
    ElMessage.success('已保存运营配置')
  } catch (failure) {
    error.value = getErrorMessage(failure, '保存运营配置失败')
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.operations-page { display: grid; gap: 16px; padding: 24px; }
.toolbar, .related { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
.toolbar h1 { margin: 0 auto 0 0; font-size: 24px; }
.toolbar :deep(.el-button + .el-button) { margin: 0; }
.configuration-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(320px, 100%), 1fr)); gap: 16px; }
.announcement { margin-top: 16px; }
.preview { padding: 12px; border-radius: 6px; background: var(--el-color-info-light-9); overflow-wrap: anywhere; white-space: pre-wrap; }
.operations-page a { color: var(--el-color-primary); }
.related span { color: var(--el-text-color-secondary); }
</style>
