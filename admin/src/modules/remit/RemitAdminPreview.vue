<template>
  <div class="remit-admin">
    <aside class="remit-sidebar">
      <div class="remit-brand"><span class="remit-logo">K</span><strong>筷子闪汇</strong></div>
      <div class="remit-menu-title">汇款运营</div>
      <el-menu
        ref="menu"
        :default-active="activeMenu"
        :default-openeds="initialOpenGroups"
        class="remit-menu"
        unique-opened
        @select="selectSection"
      >
        <el-menu-item index="dashboard">
          <el-icon><Odometer /></el-icon>
          <span>{{ menuLabels.dashboard }}</span>
        </el-menu-item>
        <el-sub-menu v-for="group in navigationGroups" :key="group.key" :index="group.key">
          <template #title>
            <el-icon><component :is="group.icon" /></el-icon>
            <span>{{ group.label }}</span>
          </template>
          <el-menu-item v-for="item in group.items" :key="item" :index="item">
            {{ menuLabels[item] }}
          </el-menu-item>
        </el-sub-menu>
      </el-menu>
      <div class="remit-sidebar-footer">
        <router-link to="/login">正式后台登录</router-link>
      </div>
    </aside>

    <main class="remit-main">
      <header class="remit-topbar">
        <span>{{ activeGroup?.label || '运营管理' }} / {{ currentLabel }}</span>
        <el-tag effect="plain" type="info">演示数据</el-tag>
      </header>

      <RemitRulesPreview v-show="rulesOpen && !activeWorkbench" @record="recordAction" />
      <RemitWorkbench v-show="activeWorkbench" :view="activeWorkbench" :audit-rows="auditRows" @record="recordAction" />
      <ListPageLayout v-if="!rulesOpen && !activeWorkbench" :class="{ 'reason-list': section === 'reasons' }">
        <template #toolbar>
          <div class="remit-title"><h1>{{ currentLabel }}</h1><span>{{ visibleRows.length }} 条记录</span></div>
        </template>
        <template #search>
          <el-form inline class="remit-search" @submit.prevent="search">
            <el-form-item label="关键词">
              <el-input v-model="searchText" :placeholder="section === 'reasons' ? '搜索原因码或提示' : '搜索编号或用户'" clearable />
            </el-form-item>
            <el-form-item label="状态">
              <el-select v-model="searchStatus" placeholder="全部状态" clearable class="remit-select">
                <el-option v-for="status in statuses" :key="status" :label="status" :value="status" />
              </el-select>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" native-type="submit">查询</el-button>
              <el-button @click="reset">重置</el-button>
            </el-form-item>
            <el-form-item v-if="section === 'support'">
              <el-checkbox v-model="onlyUnread">仅看未读</el-checkbox>
            </el-form-item>
          </el-form>
        </template>

        <BaseTable :key="section" :columns="columns" :data="visibleRows" row-key="id" empty-text="暂无匹配记录" />
      </ListPageLayout>
    </main>

    <el-drawer v-model="detailOpen" :title="detailLabel + '详情'" size="min(560px, 100%)" :before-close="closeDetail">
      <template v-if="detailRow">
        <el-button v-if="showRelated" class="remit-detail-back" link type="primary" @click="showRelated = false">
          {{ section === 'documents' ? '返回补件详情' : '返回工单详情' }}
        </el-button>
        <div class="remit-detail-title">
          <strong>{{ detailRow.id }}</strong>
          <el-tag :type="statusTypes[detailRow.status] || 'info'">{{ detailRow.status }}</el-tag>
        </div>
        <el-descriptions :column="1" border>
          <el-descriptions-item v-for="field in detailFields" :key="field.key" :label="field.label">
            <el-button v-if="field.key === 'order' && relatedOrder" link type="primary" @click="openRelatedOrder">
              {{ detailRow.order }}
            </el-button>
            <template v-else>{{ detailRow[field.key] || '—' }}</template>
          </el-descriptions-item>
        </el-descriptions>
        <template v-if="detailSection === 'orders'">
          <h3 class="remit-detail-heading">资金进度</h3>
          <el-timeline>
            <el-timeline-item :timestamp="detailRow.time">订单已创建</el-timeline-item>
            <el-timeline-item timestamp="入金">{{ detailRow.funding }}</el-timeline-item>
            <el-timeline-item timestamp="出款">{{ detailRow.payout }}</el-timeline-item>
            <el-timeline-item v-if="detailRow.refund !== '—'" timestamp="退款">{{ detailRow.refund }}</el-timeline-item>
          </el-timeline>
        </template>
        <template v-if="detailSection === 'identity'">
          <p class="remit-note">认证状态和原因由上游返回。</p>
        </template>
        <template v-if="detailSection === 'customers'">
          <h3 class="remit-detail-heading">账户风控</h3>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="账户操作状态">{{ restrictions[detailRow.id] || '正常' }}</el-descriptions-item>
          </el-descriptions>
          <el-button class="remit-save" @click="openRisk">调整演示账户状态</el-button>
        </template>
        <template v-if="detailSection === 'documents' || detailSection === 'support'">
          <h3 class="remit-detail-heading">{{ section === 'support' ? '工单回复' : '处理备注' }}</h3>
          <p v-if="detailSection === 'support'" class="remit-note">客户 · {{ detailRow.subject }}</p>
          <p
            v-for="(note, index) in notes[detailRow.id] || []"
            :key="index"
            class="remit-note"
          >{{ detailSection === 'support' ? '客服 · ' + note : note }}</p>
          <el-input v-model="draft" type="textarea" :rows="3" maxlength="500" show-word-limit placeholder="输入处理说明" />
          <el-button class="remit-save" type="primary" :disabled="!draft.trim()" @click="saveNote">保存演示记录</el-button>
        </template>
      </template>
    </el-drawer>
    <el-dialog v-model="riskOpen" title="账户风控" width="min(480px, 95vw)">
      <el-form label-position="top" @submit.prevent="saveRisk">
        <el-form-item label="账户状态">
          <el-select v-model="riskState">
            <el-option v-for="item in riskStates" :key="item" :value="item" :label="item" />
          </el-select>
        </el-form-item>
        <el-form-item label="处理原因">
          <el-input v-model="riskReason" maxlength="200" type="textarea" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="riskOpen = false">取消</el-button>
        <el-button type="primary" :disabled="!riskReason.trim()" @click="saveRisk">保存演示状态</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { MenuInstance } from 'element-plus'
import { ElMessage } from 'element-plus'
import { Coin, DataAnalysis, Document, Odometer, Service, Setting, User } from '@element-plus/icons-vue'
import BaseTable from '@/components/base-table/BaseTable.vue'
import ListPageLayout from '@/components/list-page-layout/ListPageLayout.vue'
import type { BaseTableColumn, BaseTableTagMapItem } from '@/components/base-table/types'
import {
  modules,
  previewCustomers,
  previewDocuments,
  previewIdentity,
  previewOrders,
  previewRecipients,
  previewReasons,
  previewTickets
} from './remitPreviewData'
import RemitRulesPreview from './RemitRulesPreview.vue'
import RemitWorkbench from './RemitWorkbench.vue'
import { workbenchMenus } from './remitWorkbenchData'
import type { AuditRow, WorkbenchKey } from './remitWorkbenchData'

type Section = typeof modules[number]['key']
type MenuKey = Section | WorkbenchKey | 'rules'
type PreviewRow = Record<string, string> & { id: string; status: string }

const menuLabels: Record<string, string> = {
  ...Object.fromEntries([...modules, ...workbenchMenus].map(item => [item.key, item.label])),
  rules: '付款与到账规则'
}
const navigationGroups = [
  { key: 'transactions', label: '汇款管理', icon: Document, items: ['orders', 'documents'] },
  { key: 'accounts', label: '用户与认证', icon: User, items: ['customers', 'identity', 'recipients'] },
  { key: 'pricing-rules', label: '报价与规则', icon: DataAnalysis, items: ['rules', 'pricing', 'quotes', 'profit'] },
  { key: 'marketing', label: '营销与通知', icon: Coin, items: ['templates', 'coupons', 'push'] },
  { key: 'support-finance', label: '客服与财务', icon: Service, items: ['support', 'reasons', 'finance'] },
  { key: 'system-management', label: '系统管理', icon: Setting, items: ['ops', 'roles', 'audit'] }
] satisfies Array<{ key: string; label: string; icon: typeof Document; items: MenuKey[] }>

const fieldLists: Record<Section, [string, string][]> = {
  orders: [['id', '订单编号'], ['customer', '汇款人'], ['method', '到账方式'], ['eur', '汇出金额'], ['cny', '到账金额'], ['funding', '入金'], ['payout', '出款'], ['refund', '退款'], ['time', '创建时间']],
  customers: [['id', '用户编号'], ['customer', '姓名'], ['phone', '手机号'], ['document', '证件类型'], ['time', '创建时间']],
  identity: [['id', '认证编号'], ['user', '用户编号'], ['customer', '用户'], ['document', '证件类型'], ['updated', '更新时间'], ['reason', '状态说明']],
  recipients: [['id', '账户编号'], ['user', '用户编号'], ['customer', '实名用户'], ['method', '到账方式'], ['account', '账户标识']],
  documents: [['id', '补件编号'], ['order', '关联订单'], ['customer', '汇款人'], ['document', '所需资料'], ['deadline', '截止时间'], ['assignee', '负责人']],
  support: [['id', '工单编号'], ['order', '关联订单'], ['customer', '汇款人'], ['type', '服务类型'], ['subject', '问题'], ['assignee', '负责人']],
  reasons: [['id', '原因码'], ['subject', '客户提示'], ['guidance', '客服处理建议']]
}
const statusTypes: Record<string, BaseTableTagMapItem['type']> = {
  已完成: 'success',
  已认证: 'success',
  已核验: 'success',
  已退款: 'info',
  未通过: 'danger',
  待补件: 'warning',
  待审核: 'warning',
  待处理: 'warning',
  处理中: 'primary',
  审核中: 'primary',
  付款核验中: 'primary'
}
const data: Record<Section, PreviewRow[]> = {
  orders: previewOrders,
  customers: previewCustomers,
  identity: previewIdentity,
  recipients: previewRecipients,
  documents: previewDocuments,
  support: previewTickets,
  reasons: previewReasons
}
const section = ref<Section>('orders')
const activeWorkbench = ref<WorkbenchKey>()
const auditRows = ref<AuditRow[]>([])
const restrictions = reactive<Record<string, string>>({})
const riskStates = ['正常', '限制汇款', '冻结账户']
const riskOpen = ref(false)
const riskState = ref('正常')
const riskReason = ref('')
const rulesOpen = ref(false)
const onlyUnread = ref(false)
const unreadTickets = reactive(new Set(['TK301', 'TK300']))
const searchText = ref('')
const searchStatus = ref('')
const appliedSearch = ref('')
const appliedStatus = ref('')
const detailOpen = ref(false)
const selected = ref<PreviewRow>()
const showRelated = ref(false)
const draft = ref('')
const notes = reactive<Record<string, string[]>>({})
const relatedOrder = computed(() => data.orders.find(row => row.id === selected.value?.order))
const activeMenu = computed(() => activeWorkbench.value || (rulesOpen.value ? 'rules' : section.value))
const currentLabel = computed(() => menuLabels[activeMenu.value])
const activeGroup = computed(() => navigationGroups.find(group => group.items.some(item => item === activeMenu.value)))
const route = useRoute()
const router = useRouter()
const menu = ref<MenuInstance>()
const detailRow = computed(() => showRelated.value ? relatedOrder.value : selected.value)
const detailSection = computed(() => showRelated.value ? 'orders' : section.value)
const detailLabel = computed(() => showRelated.value ? '关联汇款订单' : currentLabel.value)
const statuses = computed(() => [...new Set(data[section.value].map(row => row.status))])
const detailFields = computed(() => fieldLists[detailSection.value].filter(([key]) => key !== 'id').map(([key, label]) => ({ key, label })))
const visibleRows = computed(() => data[section.value].filter(row => {
  const matchesStatus = !appliedStatus.value || row.status === appliedStatus.value
  const matchesText = !appliedSearch.value || Object.values(row).some(value => value.toLowerCase().includes(appliedSearch.value))
  const matchesUnread = section.value !== 'support' || !onlyUnread.value || unreadTickets.has(row.id)
  return matchesStatus && matchesText && matchesUnread
}))
const reasonColumnWidths: Record<string, number> = { id: 225, subject: 98, guidance: 190 }
const columns = computed<BaseTableColumn[]>(() => [
  ...fieldLists[section.value].map(([key, label]) => ({
    key,
    label,
    minWidth: section.value === 'reasons' ? reasonColumnWidths[key] : key === 'time' || key === 'deadline' ? 150 : 98,
    showOverflowTooltip: section.value !== 'reasons'
  })),
  { key: 'status', label: '状态', type: 'tag', minWidth: 108, tagType: value => statusTypes[String(value)] || 'info' },
  { key: 'actions', label: '操作', type: 'actions', align: 'right', fixed: 'right', width: 82, actions: [{ label: '详情', onClick: row => openDetail(row as PreviewRow) }] }
])

function search() {
  appliedSearch.value = searchText.value.trim().toLowerCase()
  appliedStatus.value = searchStatus.value
}

function reset() {
  searchText.value = ''
  searchStatus.value = ''
  onlyUnread.value = false
  search()
}

function selectSection(key: string) {
  if (key === activeMenu.value) return
  void router.push({ name: 'RemitPreview', query: { ...route.query, view: key } })
}

function showSection(key: string) {
  activeWorkbench.value = undefined
  rulesOpen.value = key === 'rules'
  showRelated.value = false
  detailOpen.value = false
  riskOpen.value = false
  const workbench = workbenchMenus.find(item => item.key === key)
  if (workbench) {
    activeWorkbench.value = workbench.key
    return
  }
  const listSection = modules.find(item => item.key === key)
  if (listSection) {
    section.value = listSection.key
    reset()
  }
}

watch(() => route.query.view, (view) => {
  if (route.name !== 'RemitPreview') return
  const key = typeof view === 'string' && Object.hasOwn(menuLabels, view) ? view : 'orders'
  showSection(key)
  window.scrollTo(0, 0)
}, { immediate: true })

const initialOpenGroups = activeGroup.value ? [activeGroup.value.key] : []

watch(activeGroup, (group) => {
  if (group) {
    menu.value?.open(group.key)
  } else {
    for (const item of navigationGroups) menu.value?.close(item.key)
  }
}, { flush: 'post' })

function recordAction(action: string, target: string, details: string) {
  auditRows.value.unshift({
    id: auditRows.value.length + 1,
    time: new Date().toLocaleString('zh-CN'),
    action,
    target,
    details
  })
}

function openRisk() {
  if (!detailRow.value) return
  riskState.value = restrictions[detailRow.value.id] || '正常'
  riskReason.value = ''
  riskOpen.value = true
}

function saveRisk() {
  const user = detailRow.value
  if (!user || !riskReason.value.trim()) return
  const previous = restrictions[user.id] || '正常'
  restrictions[user.id] = riskState.value
  recordAction('调整账户状态', user.id, `${previous} → ${riskState.value}。原因：${riskReason.value.trim()}`)
  riskOpen.value = false
  ElMessage.success('已保存演示状态')
}

function openDetail(row: PreviewRow) {
  if (section.value === 'support') {
    unreadTickets.delete(row.id)
  }
  selected.value = row
  showRelated.value = false
  draft.value = ''
  detailOpen.value = true
}

function openRelatedOrder() {
  if (relatedOrder.value) showRelated.value = true
}

function closeDetail(done: () => void) {
  if (showRelated.value) {
    showRelated.value = false
    return
  }
  done()
}

function saveNote() {
  const value = draft.value.trim()
  if (!selected.value || !value) {
    return
  }
  const id = selected.value.id
  const entries = notes[id] ?? []
  notes[id] = [...entries, value]
  draft.value = ''
  ElMessage.success('已保存到当前预览')
}
</script>

<style scoped>
.remit-admin {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 208px minmax(0, 1fr);
  background: #f5f6f9;
}
.remit-sidebar {
  position: sticky;
  top: 0;
  align-self: start;
  max-height: 100vh;
  overflow-y: auto;
  background: #fff;
  border-right: 1px solid #ebeef3;
}
.remit-brand { height: 68px; display: flex; align-items: center; gap: 10px; padding: 0 20px; font-size: 18px; }
.remit-logo { width: 32px; height: 32px; display: grid; place-items: center; background: #5e51f1; color: #fff; border-radius: 8px; font-weight: 800; }
.remit-menu-title { padding: 18px 20px 6px; color: #778196; font-size: 12px; }
.remit-menu { border-right: 0; }
.remit-menu :deep(.el-menu-item),
.remit-menu :deep(.el-sub-menu__title) {
  height: 44px;
  line-height: 44px;
}
.remit-menu :deep(.el-sub-menu .el-menu-item) {
  min-width: 0;
  padding-inline-start: 46px;
}
.remit-menu :deep(.el-menu-item:hover) {
  background: #f5f7fa;
}
.remit-menu :deep(.el-menu-item.is-active) {
  background: #edf4ff;
  color: #1554c0;
  font-weight: 600;
}
.remit-sidebar-footer {
  margin: 12px 16px 16px;
  padding: 12px 4px 0;
  border-top: 1px solid #ebeef3;
}
.remit-sidebar-footer a {
  color: #526078;
  font-size: 13px;
}
.remit-main { min-width: 0; }
.remit-topbar { height: 68px; background: white; display: flex; align-items: center; justify-content: space-between; padding: 0 28px; border-bottom: 1px solid #ebeef3; }
.remit-main :deep(.page-container) { padding: 24px 28px; }
.remit-title { display: flex; align-items: baseline; gap: 12px; }
.remit-title h1 { margin: 0; font-size: 24px; }
.remit-title span { color: #697589; }
.remit-search {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px 32px;
  padding: 16px;
  background: white;
  border-radius: 8px;
}
.remit-search :deep(.el-form-item) {
  align-items: center;
  min-width: 0;
  max-width: 100%;
  margin: 0;
}
.remit-search :deep(.el-input__wrapper),
.remit-search :deep(.el-select__wrapper),
.remit-search :deep(.el-button) {
  box-sizing: border-box;
  height: var(--omc-control-height);
}
.remit-select { width: 150px; }
.reason-list :deep(.el-table .cell) {
  overflow-wrap: anywhere;
}
.remit-detail-title { display: flex; align-items: center; justify-content: space-between; padding-bottom: 20px; }
.remit-detail-back { margin-bottom: 16px; }
.remit-detail-heading { margin-top: 28px; }
.remit-save { margin-top: 12px; }
.remit-note { overflow-wrap: anywhere; white-space: pre-wrap; }
@media (max-width: 800px) {
  .remit-admin { grid-template-columns: 150px minmax(0, 1fr); }
  .remit-main :deep(.page-container) { padding: 18px; }
}
</style>
