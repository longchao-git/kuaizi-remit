<template>
  <view class="body closure-page">
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <view v-if="loaded && canSubmit" class="card closure-card">
      <text class="heading">申请注销账号</text>
      <text class="description">提交后由工作人员审核。请先处理未完成的汇款和退款。</text>
      <textarea v-model="reason" maxlength="300" class="closure-reason" placeholder="注销原因（选填）" :disabled="busy" />
      <button class="primary" :disabled="busy" @tap="confirm">提交注销申请</button>
    </view>
    <text v-if="items.length" class="heading">注销申请记录</text>
    <view v-for="item in items" :key="item.id" class="card closure-card">
      <view class="line"><text>申请 #{{ item.id }}</text><text class="pill">{{ labels[item.status] }}</text></view>
      <text class="description">{{ new Date(item.created_at).toLocaleString() }}</text>
      <text v-if="item.reason" class="description">{{ item.reason }}</text>
      <text v-if="item.review_note" class="description">审核说明：{{ item.review_note }}</text>
      <text v-if="item.reviewed_at" class="description">{{ new Date(item.reviewed_at).toLocaleString() }}</text>
    </view>
    <view v-if="pages > 1" class="line">
      <button class="inline-action" :disabled="busy || page === 1" @tap="load(page - 1)">上一页</button>
      <text>{{ page }} / {{ pages }}</text>
      <button class="inline-action" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
    </view>
    <button class="inline-action accent" :disabled="busy" @tap="load(page)">{{ busy ? '请稍候' : '刷新记录' }}</button>
  </view>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import request, { UnauthorizedError } from '@/request/http';

const labels = { pending: '待审核', approved: '审核通过，待执行', rejected: '审核未通过' };
interface Closure {
  id: string;
  status: keyof typeof labels;
  reason: string | null;
  review_note: string | null;
  reviewed_at: string | null;
  created_at: string;
}

const emit = defineEmits<{ expired: [] }>();
const path = 'app/account/closures';
const options = { handleUnauthorized: false };
const items = ref<Closure[]>([]);
const reason = ref('');
const error = ref('');
const busy = ref(false);
const loaded = ref(false);
const canSubmit = ref(false);
const page = ref(1);
const pages = ref(1);

async function readRecords(next: number) {
  const result = await request.get<{ list: Closure[]; page: number; total_pages: number }>(path, { page: next }, options);
  items.value = result.list;
  page.value = result.page;
  pages.value = result.total_pages;
  if (next === 1) canSubmit.value = !result.list[0] || result.list[0].status === 'rejected';
  loaded.value = true;
}

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (failure) {
    if (failure instanceof UnauthorizedError) emit('expired');
    else error.value = failure instanceof Error ? failure.message : '操作失败，请重试';
  } finally {
    busy.value = false;
  }
}

function load(next: number) {
  return run(() => readRecords(next));
}

function confirm() {
  uni.showModal({
    title: '确认提交注销申请',
    content: '提交后仍可登录查看申请与订单进度。',
    confirmText: '提交申请',
    success: ({ confirm: accepted }) => {
      if (!accepted) return;
      void run(async () => {
        const item = await request.post<Closure>(path, { reason: reason.value.trim() || null }, options);
        canSubmit.value = false;
        reason.value = '';
        items.value = [item, ...items.value.filter(existing => existing.id !== item.id)];
        await readRecords(1);
      });
    },
  });
}

onMounted(() => { void load(1); });
</script>

<style scoped lang="scss">
@use './remit.scss';
.closure-page { display: flex; flex-direction: column; gap: 28rpx; }
.closure-card { display: grid; gap: 20rpx; padding: 28rpx; overflow-wrap: anywhere; }
.closure-reason { box-sizing: border-box; width: 100%; height: 150rpx; padding: 20rpx; background: #f5f5fa; border-radius: 20rpx; }
</style>
