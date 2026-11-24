<template>
  <view class="body draft-list">
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-else-if="busy" class="description">正在读取…</text>
    <text v-else-if="!items.length" class="description">暂无汇款草稿</text>
    <view v-for="draft in items" :key="draft.record_no" class="card draft-card">
      <text class="draft-amount">€{{ formatAmount(draft.principal_minor) }}</text>
      <text class="description">{{ payments[draft.payment_method] }} · {{ payouts[draft.payout_method] }}</text>
      <text class="description">{{ new Date(draft.draft_saved_at).toLocaleString() }}</text>
      <view class="line">
        <button class="inline-action accent" :disabled="busy" @tap="$emit('resume', draft)">继续试算</button>
        <button class="inline-action" :disabled="busy" @tap="confirmRemove(draft)">移除草稿</button>
      </view>
    </view>
    <view v-if="pages > 1" class="line">
      <button class="inline-action" :disabled="busy || page <= 1" @tap="load(page - 1)">上一页</button>
      <text>{{ page }} / {{ pages }}</text>
      <button class="inline-action" :disabled="busy || page >= pages" @tap="load(page + 1)">下一页</button>
    </view>
    <button v-if="error" class="inline-action accent" :disabled="busy" @tap="load(page)">重新加载</button>
  </view>
</template>

<script lang="ts">
import type { RemittanceDeclaration } from './remittanceDeclaration';
export interface RemitDraft {
  record_no: string;
  principal_minor: number;
  payment_method: 'sepa' | 'applePay' | 'card';
  payout_method: 'wechat' | 'bank';
  draft_saved_at: string;
  declaration?: RemittanceDeclaration;
}
</script>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import request, { UnauthorizedError } from '@/request/http';
import { formatAmount } from './previewOrder';

const emit = defineEmits<{ expired: []; resume: [draft: RemitDraft] }>();
const items = ref<RemitDraft[]>([]);
const busy = ref(false);
const error = ref('');
const page = ref(1);
const pages = ref(0);
const options = { handleUnauthorized: false };
const payments = { sepa: 'SEPA 转账', applePay: 'Apple Pay', card: '银行卡付款' };
const payouts = { wechat: '微信收款', bank: '银行卡收款' };

async function run(action: () => Promise<void>) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (reason instanceof UnauthorizedError) emit('expired');
    else error.value = reason instanceof Error ? reason.message : '操作失败，请重试';
  } finally {
    busy.value = false;
  }
}

async function query(next: number) {
  const result = await request.get<{ list: RemitDraft[]; page: number; total_pages: number }>(
    'app/remittance/drafts', { page: next }, options,
  );
  if (next > 1 && next > result.total_pages) {
    await query(Math.max(1, result.total_pages));
    return;
  }
  items.value = result.list;
  page.value = result.page;
  pages.value = result.total_pages;
}

function load(next: number) {
  void run(() => query(next));
}

function confirmRemove(draft: RemitDraft) {
  uni.showModal({
    title: '移除草稿',
    content: '从我的汇款草稿中移除这条记录？',
    success: ({ confirm }) => {
      if (confirm) {
        void run(async () => {
          await request.post(`app/remittance/drafts/${draft.record_no}/remove`, {}, options);
          await query(page.value);
        });
      }
    },
  });
}

onMounted(() => load(1));
</script>

<style scoped>
.draft-list { display: grid; gap: 20rpx; }
.draft-card { display: grid; gap: 12rpx; padding: 22rpx; }
.draft-amount { font-size: 34rpx; font-weight: 700; }
</style>
