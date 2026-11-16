<template>
  <section class="staff-page">
    <h1>员工角色</h1>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-card class="remit-card" shadow="never">
      <template #header><h2>员工列表</h2></template>
      <el-table v-loading="loading" :data="staff" empty-text="暂无员工">
        <el-table-column prop="name" label="姓名" min-width="120" />
        <el-table-column prop="email" label="邮箱" min-width="180" />
        <el-table-column label="当前角色" min-width="120"><template #default="{ row }">{{ labels[row.role] }}</template></el-table-column>
        <el-table-column label="操作" min-width="155">
          <template #default="{ row }">
            <el-button v-if="row.role !== 'administrator'" link type="primary" :disabled="saving" @click="openRole(row)">分配角色</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination v-if="staffTotal > 20" :current-page="staffPage" :disabled="loading" :page-size="20" :total="staffTotal" layout="prev, pager, next" @current-change="loadStaff" />
    </el-card>
    <el-card class="remit-card" shadow="never">
      <template #header><h2>角色变更记录</h2></template>
      <el-table v-loading="auditLoading" :data="audits" empty-text="暂无记录">
        <el-table-column prop="operator_name" label="操作人" min-width="110" />
        <el-table-column prop="target_name" label="员工" min-width="110" />
        <el-table-column label="原角色" min-width="110"><template #default="{ row }">{{ labels[row.before_role ?? 'legacy'] }}</template></el-table-column>
        <el-table-column label="新角色" min-width="110"><template #default="{ row }">{{ labels[row.after_role] }}</template></el-table-column>
        <el-table-column prop="created_at" label="更新时间" min-width="170" />
      </el-table>
      <el-pagination v-if="auditTotal > 20" :current-page="auditPage" :disabled="auditLoading" :page-size="20" :total="auditTotal" layout="prev, pager, next" @current-change="loadAudits" />
    </el-card>
    <el-dialog v-model="dialog" title="分配角色" width="min(440px, 92vw)" :before-close="closeRole" :close-on-click-modal="false">
      <el-form label-position="top" @submit.prevent="save">
        <el-form-item label="员工">{{ selected?.name }} · {{ selected?.email }}</el-form-item>
        <el-form-item label="角色">
          <el-select v-model="role" :disabled="saving" placeholder="请选择角色">
            <el-option v-for="item in roles" :key="item" :value="item" :label="labels[item]" />
          </el-select>
        </el-form-item>
        <p>修改后，该员工需要重新登录。</p>
        <el-alert v-if="saveError" :title="saveError" type="error" :closable="false" />
        <el-button type="primary" native-type="submit" :disabled="!role" :loading="saving">保存角色</el-button>
      </el-form>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getErrorMessage } from '@/utils/error'
import { assignRole, listRoleAudits, listStaff, type Staff, type RoleAudit, type AssignableRole } from './repository/remitStaffRepository'

const roles = ['operations', 'support', 'read_only', 'finance'] as const
const labels: Record<string, string> = { administrator: '管理员', operations: '运营', support: '客服', read_only: '只读', finance: '财务', legacy: '原有权限' }
const staff = ref<Staff[]>([])
const audits = ref<RoleAudit[]>([])
const staffPage = ref(1)
const auditPage = ref(1)
const staffTotal = ref(0)
const auditTotal = ref(0)
const loading = ref(false)
const auditLoading = ref(false)
const saving = ref(false)
const dialog = ref(false)
const error = ref('')
const saveError = ref('')
const selected = ref<Staff>()
const role = ref<AssignableRole>()

async function loadStaff(next: number) {
  loading.value = true
  try {
    const result = await listStaff(next)
    staff.value = result.list
    staffTotal.value = result.total
    staffPage.value = result.page
  } catch (reason) {
    error.value = getErrorMessage(reason, '读取员工失败')
  } finally {
    loading.value = false
  }
}

async function loadAudits(next: number) {
  auditLoading.value = true
  try {
    const result = await listRoleAudits(next)
    audits.value = result.list
    auditTotal.value = result.total
    auditPage.value = result.page
  } catch (reason) {
    error.value = getErrorMessage(reason, '读取角色变更记录失败')
  } finally {
    auditLoading.value = false
  }
}

function openRole(row: Staff) {
  selected.value = row
  role.value = roles.find(item => item === row.role)
  saveError.value = ''
  dialog.value = true
}

function closeRole(done: () => void) {
  if (!saving.value) done()
}

async function save() {
  if (!role.value || !selected.value || saving.value) return
  saving.value = true
  saveError.value = ''
  try {
    await assignRole(selected.value.id, role.value)
    dialog.value = false
    ElMessage.success('已保存角色')
    error.value = ''
    await Promise.all([loadStaff(staffPage.value), loadAudits(1)])
  } catch (reason) {
    saveError.value = getErrorMessage(reason, '保存角色失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  void loadStaff(1)
  void loadAudits(1)
})
</script>

<style scoped>
.staff-page { display: grid; gap: 16px; }
.staff-page h1, .staff-page h2 { margin: 0; }
</style>
