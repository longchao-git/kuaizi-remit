<template>
  <RemitRulesPreview connected @saved="reloadAfterSave" />
  <el-card class="rule-history remit-card" shadow="never">
    <template #header>
      <header>
        <h2>规则保存记录</h2>
        <el-button :loading="loading" @click="load(1)">刷新</el-button>
      </header>
    </template>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="items" :empty-text="error ? '读取失败' : '暂无保存记录'">
      <el-table-column type="expand">
        <template #default="{ row }">
          <div class="snapshot">
            <p v-if="row.settings.announcement">
              首页公告：{{ row.settings.announcement.enabled ? row.settings.announcement.text : '关闭' }}
            </p>
            <p>首单免基础手续费：{{ row.settings.first_order_free ? '开启' : '关闭' }}</p>
            <p>EUR → CNY 点差：{{ (row.settings.spread_basis_points ?? 0) / 100 }}%</p>
            <p v-for="(rule, channel) in row.settings.payment" :key="channel">
              {{ paymentLabels[String(channel)] }} · 费率 {{ rule.basis_points / 100 }}%
              <template v-if="rule.notice_enabled === false"> · 付款提示已关闭</template>
              <template v-else-if="rule.notice"> · {{ rule.notice }}</template>
            </p>
            <p v-for="(rule, channel) in row.settings.eta" :key="channel">
              {{ etaLabels[String(channel)] }} · 低于 {{ euro(rule.threshold_minor) }}：{{ rule.below }}；达到及以上：{{ rule.at_or_above }}
              <span v-for="band in rule.bands ?? []" :key="band.from_minor + ':' + band.until_minor + ':' + band.label" class="snapshot-band">
                {{ euro(band.from_minor) }} 起 · {{ band.until_minor === null ? '无上限' : `低于 ${euro(band.until_minor)}` }} · {{ band.label }} · {{ band.enabled ? '启用' : '停用' }}
              </span>
            </p>
          </div>
        </template>
      </el-table-column>
      <el-table-column prop="id" label="版本" width="90" />
      <el-table-column prop="staff_name" label="操作人" min-width="140" />
      <el-table-column label="基础手续费" min-width="130">
        <template #default="{ row }">{{ euro(row.settings.base_fee_minor) }}</template>
      </el-table-column>
      <el-table-column label="保存时间" min-width="170">
        <template #default="{ row }">{{ new Date(row.created_at).toLocaleString() }}</template>
      </el-table-column>
    </el-table>
    <el-pagination
      v-if="total > pageSize" :current-page="page" :page-size="pageSize" :total="total"
      :disabled="loading" layout="prev, pager, next" @current-change="load"
    />
  </el-card>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { getErrorMessage } from '@/utils/error'
import RemitRulesPreview from './RemitRulesPreview.vue'
import { listRuleRevisions, type RuleRevision } from './repository/remitOperationsRepository'

const items = ref<RuleRevision[]>([])
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const loading = ref(false)
const error = ref('')
const paymentLabels: Record<string, string> = { sepa: 'SEPA', applePay: 'Apple Pay', card: '银行卡付款' }
const etaLabels: Record<string, string> = { wechat: '微信收款', bank: '银行卡收款' }

function euro(cents: number) {
  return `€${(cents / 100).toFixed(2)}`
}

let version = 0

async function load(next: number) {
  const requestVersion = ++version
  loading.value = true
  error.value = ''
  try {
    const result = await listRuleRevisions(next)
    if (requestVersion !== version) return
    items.value = result.list
    page.value = result.page
    pageSize.value = result.page_size
    total.value = result.total
  } catch (reason) {
    if (requestVersion === version) error.value = getErrorMessage(reason, '读取保存记录失败，请重试')
  } finally {
    if (requestVersion === version) loading.value = false
  }
}

function reloadAfterSave() {
  void load(1)
}

onMounted(() => void load(1))
</script>

<style scoped>
.rule-history header { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.rule-history .el-alert { margin-bottom: 12px; }
.snapshot { padding: 8px 24px; overflow-wrap: anywhere; }
.snapshot-band { display: block; padding-left: 16px; }
</style>
