<template>
  <section class="coupons">
    <header><h1>汇款优惠券</h1><el-button v-if="activeTab === 'coupons'" type="primary" @click="openIssue()">发放优惠券</el-button></header>
    <el-tabs v-model="activeTab">
      <el-tab-pane label="发券记录" name="coupons">
        <el-alert v-if="error" :title="error" type="error" :closable="false" />
        <el-table v-loading="loading" :data="rows" :empty-text="error ? '读取失败' : '暂无优惠券'">
          <el-table-column prop="title" label="名称" min-width="145" />
          <el-table-column label="基础手续费减免" width="145">
            <template #default="{ row }">{{ couponTypeLabels[row.coupon_type as CouponType] }} · {{ row.discount_bps / 100 }}%</template>
          </el-table-column>
          <el-table-column label="收款渠道" min-width="100">
            <template #default="{ row }">{{ channelLabels[row.payout_method ?? 'all'] }}</template>
          </el-table-column>
          <el-table-column label="领取方式" min-width="100">
            <template #default="{ row }">{{ deliveryLabels[row.delivery] }}</template>
          </el-table-column>
          <el-table-column prop="code" label="一次性优惠码" min-width="205">
            <template #default="{ row }">{{ row.status === 'unclaimed' ? row.code : '—' }}</template>
          </el-table-column>
          <el-table-column prop="member_id" label="会员编号" width="105" />
          <el-table-column label="状态" width="100">
            <template #default="{ row }">{{ labels[row.status] }}</template>
          </el-table-column>
          <el-table-column label="有效期至" min-width="160">
            <template #default="{ row }">{{ new Date(row.expires_at).toLocaleString() }}</template>
          </el-table-column>
          <el-table-column label="操作" width="85">
            <template #default="{ row }">
              <el-button v-if="revocableStates.has(row.status)" link type="danger" :disabled="revoking" @click="revoke(row)">撤销</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-pagination v-if="total > 20" :current-page="page" :page-size="20" :total="total" layout="prev, pager, next" @current-change="load" />
      </el-tab-pane>
      <el-tab-pane label="优惠券模板" name="templates" lazy>
        <RemitCouponTemplates @issue="openIssue" />
      </el-tab-pane>
    </el-tabs>
    <el-dialog v-model="dialog" title="发放优惠券" width="min(500px, 94vw)" :before-close="closeIssue" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent="issue">
        <el-form-item v-if="template" label="优惠券模板">
          <strong>{{ template.title }} · {{ couponTypeLabels[template.coupon_type] }} · {{ template.discount_bps / 100 }}% · {{ template.valid_days }} 天</strong>
        </el-form-item>
        <template v-else>
          <el-form-item label="名称"><el-input v-model.trim="title" maxlength="80" :disabled="issuing" /></el-form-item>
          <el-form-item label="优惠券类型">
            <el-radio-group v-model="couponType" :disabled="issuing">
              <el-radio value="first_order_free">新人首单免手续费</el-radio>
              <el-radio value="discount">折扣券</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="couponType === 'discount'" label="基础手续费减免比例（%）">
            <el-input-number v-model="percent" :min="0.01" :max="99.99" :step="0.01" :precision="2" :disabled="issuing" />
          </el-form-item>
          <el-form-item label="有效天数"><el-input-number v-model="days" :min="1" :max="365" :precision="0" :disabled="issuing" /></el-form-item>
        </template>
        <el-form-item label="领取方式">
          <el-radio-group v-model="delivery" :disabled="issuing">
            <el-radio value="home_popup">首页弹窗</el-radio>
            <el-radio value="code">输入优惠码</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="delivery === 'home_popup'" label="会员编号" required>
          <el-input v-model.trim="member" :disabled="issuing" placeholder="请输入收券会员编号" />
        </el-form-item>
        <el-alert v-if="issueError" :title="issueError" type="error" :closable="false" />
        <el-button native-type="submit" type="primary" :loading="issuing">确认发放</el-button>
      </el-form>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getErrorMessage } from '@/utils/error'
import RemitCouponTemplates from './RemitCouponTemplates.vue'
import {
  channelLabels, couponTypeLabels, issueCoupon, listCoupons, revokeCoupon,
  type Coupon, type CouponDelivery, type CouponTemplate, type CouponType
} from './repository/remitCouponRepository'

const labels: Record<string, string> = { available: '可用', pending_claim: '待领取', unclaimed: '未兑换', expired: '已过期', revoked: '已撤销', reserved: '待付款', redeemed: '已核销' }
const deliveryLabels: Record<string, string> = { home_popup: '首页弹窗', code: '输入优惠码', direct: '历史直发' }
const revocableStates = new Set(['available', 'pending_claim', 'unclaimed'])
const rows = ref<Coupon[]>([])
const activeTab = ref('coupons')
const template = ref<CouponTemplate>()
const page = ref(1)
const total = ref(0)
const loading = ref(false)
const revoking = ref(false)
const issuing = ref(false)
const dialog = ref(false)
const error = ref('')
const issueError = ref('')
const title = ref('')
const percent = ref<number | undefined>(50)
const couponType = ref<CouponType>('discount')
const days = ref<number | undefined>(30)
const member = ref('')
const delivery = ref<CouponDelivery>('code')
let version = 0
let pending: { key: string; id: string } | undefined

async function load(next: number) {
  const current = ++version
  loading.value = true
  error.value = ''
  try {
    const result = await listCoupons(next)
    if (current !== version) return
    rows.value = result.list
    total.value = result.total
    page.value = result.page
  } catch (reason) {
    if (current === version) error.value = getErrorMessage(reason, '读取优惠券失败')
  } finally {
    if (current === version) loading.value = false
  }
}

function openIssue(source?: CouponTemplate) {
  template.value = source
  issueError.value = ''
  dialog.value = true
}

function closeIssue(done: () => void) {
  if (!issuing.value) done()
}

async function issue() {
  if (issuing.value) return
  const memberId = delivery.value === 'home_popup' ? Number(member.value) : null
  if (memberId !== null && (!Number.isSafeInteger(memberId) || !/^\d+$/.test(member.value) || memberId < 1)) {
    issueError.value = '请输入有效的会员编号'
    return
  }
  if (!template.value && (!title.value || (couponType.value === 'discount' && !percent.value) || !days.value)) {
    issueError.value = '请填写名称、减免比例和有效期'
    return
  }
  issuing.value = true
  issueError.value = ''
  try {
    const recipient = { delivery: delivery.value, member_id: memberId }
    const input = template.value ? { ...recipient, template_id: Number(template.value.id) } : {
      ...recipient,
      title: title.value,
      discount_bps: couponType.value === 'first_order_free' ? 10000 : Math.round((percent.value ?? 0) * 100),
      valid_days: days.value ?? 0
    }
    const key = JSON.stringify(input)
    if (pending?.key !== key) pending = { key, id: crypto.randomUUID() }
    await issueCoupon({ ...input, request_id: pending.id })
    pending = undefined
    title.value = ''
    member.value = ''
    delivery.value = 'code'
    dialog.value = false
    activeTab.value = 'coupons'
    ElMessage.success('已发放优惠券')
    await load(1)
  } catch (reason) {
    issueError.value = getErrorMessage(reason, '发放失败，请重试')
  } finally {
    issuing.value = false
  }
}

async function revoke(coupon: Coupon) {
  if (revoking.value) return
  revoking.value = true
  try {
    await ElMessageBox.confirm(`撤销「${coupon.title}」后无法兑换或用于新报价。`, '撤销优惠券', { type: 'warning' })
    await revokeCoupon(coupon.id)
    await load(page.value)
  } catch (reason) {
    if (reason !== 'cancel' && reason !== 'close') ElMessage.error(getErrorMessage(reason, '撤销失败，请重试'))
  } finally {
    revoking.value = false
  }
}

onMounted(() => void load(1))
</script>

<style scoped>
.coupons { display: grid; gap: 16px; padding: 24px 28px; }
.coupons header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.coupons h1 { margin: 0; }
</style>
