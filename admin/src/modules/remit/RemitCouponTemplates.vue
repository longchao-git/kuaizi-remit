<template>
  <div class="templates">
    <el-button type="primary" class="create" @click="open()">新建优惠券模板</el-button>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows" empty-text="暂无模板">
      <el-table-column prop="title" label="模板名称" min-width="160" />
      <el-table-column label="基础手续费减免" min-width="130">
        <template #default="{ row }">{{ couponTypeLabels[row.coupon_type as CouponType] }} · {{ row.discount_bps / 100 }}%</template>
      </el-table-column>
      <el-table-column prop="valid_days" label="有效天数" min-width="100" />
      <el-table-column label="适用收款渠道" min-width="130">
        <template #default="{ row }">{{ channelLabels[row.payout_method ?? 'all'] }}</template>
      </el-table-column>
      <el-table-column label="状态" min-width="100">
        <template #default="{ row }">{{ row.enabled ? '启用' : '停用' }}</template>
      </el-table-column>
      <el-table-column label="操作" min-width="145">
        <template #default="{ row }">
          <el-button link type="primary" @click="open(row)">编辑</el-button>
          <el-button v-if="row.enabled" link type="primary" @click="$emit('issue', row)">按模板发放</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination v-if="total > 20" :current-page="page" :page-size="20" :total="total" layout="prev, pager, next" @current-change="load" />
    <el-dialog v-model="dialog" :title="id ? '编辑优惠券模板' : '新建优惠券模板'" width="min(500px, 94vw)" :before-close="close" :close-on-click-modal="false" append-to-body>
      <el-form label-position="top" @submit.prevent="save">
        <el-form-item label="模板名称"><el-input v-model.trim="title" maxlength="80" :disabled="saving" /></el-form-item>
        <el-form-item label="优惠券类型">
          <el-radio-group v-model="couponType" :disabled="saving">
            <el-radio value="first_order_free">新人首单免手续费</el-radio>
            <el-radio value="discount">折扣券</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="couponType === 'discount'" label="基础手续费减免比例（%）">
          <el-input-number v-model="percent" :min="0.01" :max="99.99" :step="0.01" :precision="2" :disabled="saving" />
        </el-form-item>
        <el-form-item label="有效天数"><el-input-number v-model="days" :min="1" :max="365" :precision="0" :disabled="saving" /></el-form-item>
        <el-form-item label="适用收款渠道">
          <el-select v-model="channel" :disabled="saving">
            <el-option v-for="(label, value) in channelLabels" :key="value" :label="label" :value="value" />
          </el-select>
        </el-form-item>
        <el-form-item label="启用模板"><el-switch v-model="enabled" aria-label="启用模板" :disabled="saving" /></el-form-item>
        <el-alert v-if="saveError" :title="saveError" type="error" :closable="false" />
        <el-button native-type="submit" type="primary" :loading="saving">保存模板</el-button>
      </el-form>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getErrorMessage } from '@/utils/error'
import { channelLabels, couponTypeLabels, listCouponTemplates, saveCouponTemplate, type CouponTemplate, type CouponType } from './repository/remitCouponRepository'

defineEmits<{ issue: [template: CouponTemplate] }>()
const channel = ref<'all' | 'wechat' | 'bank'>('all')
const rows = ref<CouponTemplate[]>([])
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const saving = ref(false)
const error = ref('')
const saveError = ref('')
const dialog = ref(false)
const id = ref<string>()
const title = ref('')
const percent = ref<number | undefined>(50)
const couponType = ref<CouponType>('discount')
const days = ref<number | undefined>(30)
const enabled = ref(true)

async function load(next: number) {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    const result = await listCouponTemplates(next)
    rows.value = result.list
    page.value = result.page
    total.value = result.total
  } catch (reason) {
    error.value = getErrorMessage(reason, '读取优惠券模板失败')
  } finally {
    loading.value = false
  }
}

function open(template?: CouponTemplate) {
  id.value = template?.id
  title.value = template?.title ?? ''
  couponType.value = template?.coupon_type ?? 'discount'
  percent.value = template?.coupon_type === 'discount' ? template.discount_bps / 100 : 50
  days.value = template?.valid_days ?? 30
  channel.value = template?.payout_method ?? 'all'
  enabled.value = template?.enabled ?? true
  saveError.value = ''
  dialog.value = true
}

function close(done: () => void) {
  if (!saving.value) done()
}

async function save() {
  if (saving.value) return
  if (!title.value || (couponType.value === 'discount' && !percent.value) || !days.value) {
    saveError.value = '请填写模板名称、减免比例和有效期'
    return
  }
  saving.value = true
  saveError.value = ''
  try {
    await saveCouponTemplate(id.value, {
      title: title.value,
      discount_bps: couponType.value === 'first_order_free' ? 10000 : Math.round((percent.value ?? 0) * 100),
      valid_days: days.value,
      payout_method: channel.value === 'all' ? null : channel.value,
      enabled: enabled.value
    })
    dialog.value = false
    ElMessage.success('已保存优惠券模板')
    await load(id.value ? page.value : 1)
  } catch (reason) {
    saveError.value = getErrorMessage(reason, '保存模板失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => void load(1))
</script>

<style scoped>
.templates { display: grid; gap: 12px; }
.create { justify-self: end; }
</style>
