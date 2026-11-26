<template>
  <view class="body payout-drafts">
    <text class="description">收款资料保存后为待核验，暂不可用于付款。请填写本人资料。</text>
    <text v-if="error" class="offer description" role="alert">{{ error }}</text>
    <view v-if="editing" class="card payout-form">
      <view class="line">
        <button class="inline-action" :class="{ accent: form.payout_method === 'bank' }" :disabled="busy" @tap="form.payout_method = 'bank'">银行卡</button>
        <button class="inline-action" :class="{ accent: form.payout_method === 'wechat' }" :disabled="busy" @tap="form.payout_method = 'wechat'">微信收款</button>
      </view>
      <input v-model="form.first_name" placeholder="本人名字（与证件一致）" maxlength="64" :disabled="busy" />
      <input v-model="form.last_name" placeholder="本人姓氏（与证件一致）" maxlength="64" :disabled="busy" />
      <text v-if="maskedAccount" class="description">已存账号 {{ maskedAccount }}；不更换账号时留空。</text>
      <input v-model="form.account" placeholder="收款账号" maxlength="64" :disabled="busy" />
      <button class="primary" :disabled="busy" @tap="save">{{ busy ? '保存中…' : '保存待核验资料' }}</button>
      <button class="inline-action" :disabled="busy" @tap="back">取消编辑</button>
    </view>
    <template v-else>
      <button class="primary" :disabled="busy" @tap="edit()">新增收款资料</button>
      <text v-if="!busy && !rows.length && !error" class="description">暂无收款资料</text>
      <view v-for="row in rows" :key="row.reference" class="card payout-form">
        <view class="line"><text>{{ row.payout_method === 'bank' ? '银行卡' : '微信收款' }}</text><text class="pill">待核验</text></view>
        <text>{{ row.first_name }} {{ row.last_name }}</text>
        <text>{{ row.masked_account }}</text>
        <button class="inline-action accent" :disabled="busy" @tap="edit(row)">编辑资料</button>
      </view>
      <view v-if="pages > 1" class="line">
        <button class="inline-action" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
        <text>{{ page }} / {{ pages }}</text>
        <button class="inline-action" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
      </view>
      <button class="inline-action accent" :disabled="busy" @tap="load(page)">刷新</button>
    </template>
  </view>
</template>

<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import request, { UnauthorizedError, ValidationError } from '@/request/http';

interface PayoutDraft {
  reference: string;
  revision: string;
  payout_method: 'bank' | 'wechat';
  first_name: string;
  last_name: string;
  masked_account: string;
}

const emit = defineEmits<{ expired: [] }>();
const path = 'app/remittance/payout-account-drafts';
const options = { handleUnauthorized: false };
const rows = ref<PayoutDraft[]>([]);
const form = ref({ first_name: '', last_name: '', account: '', payout_method: 'bank' as 'bank' | 'wechat' });
const reference = ref('');
const revision = ref<string>();
const maskedAccount = ref('');
const editing = ref(false);
const busy = ref(false);
const error = ref('');
const page = ref(1);
const pages = ref(0);
let active = true;
let confirming = false;

async function run(action: () => Promise<void>) {
  if (!active || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (!active) return;
    if (reason instanceof UnauthorizedError) emit('expired');
    else if (reason instanceof ValidationError) error.value = '请检查姓名及收款账号，每项最多64字';
    else error.value = reason instanceof Error ? reason.message : '收款资料操作失败，请重试';
  } finally {
    busy.value = false;
  }
}

async function query(next: number) {
  const result = await request.get<{ list: PayoutDraft[]; page: number; total_pages: number }>(path, { page: next }, options);
  if (!active) return;
  rows.value = result.list;
  page.value = result.page;
  pages.value = result.total_pages;
}

function load(next: number) {
  return run(() => query(next));
}

function edit(row?: PayoutDraft) {
  if (!active || busy.value) return;
  reference.value = row?.reference ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, character => {
    const random = Math.floor(Math.random() * 16);
    return (character === 'x' ? random : (random & 3) | 8).toString(16);
  });
  revision.value = row?.revision;
  maskedAccount.value = row?.masked_account ?? '';
  form.value = {
    first_name: row?.first_name ?? '', last_name: row?.last_name ?? '',
    account: '', payout_method: row?.payout_method ?? 'bank',
  };
  error.value = '';
  editing.value = true;
}

function save() {
  return run(async () => {
    const input = Object.fromEntries(Object.entries(form.value).map(([key, value]) => [key, value.trim()]));
    if (!input.first_name || !input.last_name || (!input.account && !revision.value)) throw new Error('请填写本人姓名和收款账号');
    await request.put<PayoutDraft>(`${path}/${reference.value}`, { ...input, revision: revision.value }, options);
    if (!active) return;
    editing.value = false;
    form.value.account = '';
    uni.showToast({ title: '已保存，待核验', icon: 'none' });
    await query(1);
  });
}

function back(): boolean {
  if (busy.value || confirming) return true;
  if (!editing.value) return false;
  confirming = true;
  uni.showModal({
    title: '放弃本次编辑？', content: '未保存的修改将丢失。',
    success: result => {
      if (result.confirm && active) {
        editing.value = false;
        form.value.account = '';
        error.value = '';
      }
    },
    complete: () => { confirming = false; },
  });
  return true;
}

onMounted(() => { void load(1); });
onUnmounted(() => { active = false; });
defineExpose({ back });
</script>

<style scoped>
.payout-drafts, .payout-form { display: grid; gap: 20rpx; }
.payout-form { padding: 24rpx; overflow-wrap: anywhere; }
.payout-form input { border: 1rpx solid #e1e1e7; border-radius: 14rpx; padding: 18rpx; }
</style>
