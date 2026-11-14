<template>
  <view class="patterns-page">
    <navbar title="页面组合与状态" show-back background="#f7f8fa" />
    <view class="intro">网校原页面提取的通用布局。下面均为本地演示，不提交业务数据。</view>
    <SearchBar v-model="keyword" @search="searched = $event" />
    <FilterTabs v-model="category" :items="categories" />
    <FilterTabs v-model="section" variant="underline" :items="sections" />
    <view class="pattern-content">
      <text class="feedback">搜索：{{ searched || '尚未搜索' }} · 当前分类：{{ category }}</text>
      <ContentCard landscape title="带状态的通用资料卡片" description="识别内容、查看状态，再执行所需操作。" image="/static/logo.png">
        <template #footer><text class="status-tag">示例 · 可查看</text></template>
      </ContentCard>
      <view class="controls"><button v-for="item in states" :key="item" size="mini" @click="listState = item">{{ stateNames[item] }}</button></view>
      <ListStatus :state="listState" @action="retry" />
    </view>
    <DetailSection title="图文表单">
      <TextAreaField v-model="content" label="内容说明" description="保留原输入区、字数和间距；仅演示必填规则。" required :disabled="saving" :error="formError" />
      <ImageGrid v-model="images" :upload-adapter="localImage" :readonly="saving" @busy="imageBusy = $event" />
      <view class="controls"><label><switch :checked="failUpload" @change="failUpload = ($event as CustomEvent<{ value: boolean }>).detail.value" />模拟图片处理失败</label><label><switch :checked="failSave" @change="failSave = ($event as CustomEvent<{ value: boolean }>).detail.value" />模拟保存失败</label></view>
      <text class="feedback" role="status">{{ outcome }}</text>
    </DetailSection>
    <DetailSection title="详情信息分组"><text class="description">标题、正文和附加信息沿用原详情区。业务自行提供字段，不携带课程价格、订单或支付逻辑。</text></DetailSection>
    <BottomActionBar label="保存演示内容" :loading="saving" :disabled="imageBusy" @action="save" />
  </view>
</template>
<script setup lang="ts">
import { ref } from 'vue';
import SearchBar from '@/components/search-bar/SearchBar.vue';
import FilterTabs from '@/components/filter-tabs/FilterTabs.vue';
import ListStatus from '@/components/list-status/ListStatus.vue';
import TextAreaField from '@/components/text-area-field/TextAreaField.vue';
import ImageGrid from '@/components/image-grid/ImageGrid.vue';
import BottomActionBar from '@/components/bottom-action-bar/BottomActionBar.vue';
import DetailSection from '@/components/detail-section/DetailSection.vue';
import ContentCard from '@/components/content-card/ContentCard.vue';
const keyword = ref(''); const searched = ref(''); const category = ref('all'); const section = ref('list');
const categories = [{ value: 'all', label: '全部' }, { value: 'common', label: '常用资料' }, { value: 'long', label: '用于验证横向滚动的较长分类名称' }, { value: 'disabled', label: '暂不可用', disabled: true }];
const sections = [{ value: 'list', label: '资料' }, { value: 'detail', label: '详情' }, { value: 'record', label: '记录' }];
const states = ['loading', 'empty', 'error', 'more', 'end'] as const;
const stateNames = { loading: '加载中', empty: '空数据', error: '失败', more: '更多', end: '到底' };
const listState = ref<(typeof states)[number]>('end');
const content = ref(''); const images = ref<string[]>([]); const saving = ref(false); const imageBusy = ref(false);
const failUpload = ref(false); const failSave = ref(false); const formError = ref(''); const outcome = ref('草稿仅在本页面保留');
const delay = () => new Promise(resolve => setTimeout(resolve, 450));
async function retry() { if (listState.value === 'loading') return; listState.value = 'loading'; await delay(); listState.value = 'end'; }
async function localImage(file: { path: string; size: number }) { await delay(); if (failUpload.value) throw new Error('模拟处理失败，请关闭失败开关后重新选择'); return file.path; }
async function save() {
  if (saving.value || imageBusy.value) return;
  formError.value = content.value.trim() ? '' : '请填写内容说明'; outcome.value = '';
  if (formError.value) return;
  saving.value = true;
  try { await delay(); if (failSave.value) throw new Error('模拟保存失败，草稿已保留，请重试'); outcome.value = '演示保存成功（未写入服务器）'; }
  catch (cause) { formError.value = cause instanceof Error ? cause.message : '保存失败，请重试'; }
  finally { saving.value = false; }
}
</script>
<style scoped lang="scss">
.patterns-page { min-height: 100vh; background: $app-page-bg; }
.intro { margin: 24rpx 32rpx; color: $app-text-secondary; font-size: 24rpx; line-height: 36rpx; }
.pattern-content { padding: 32rpx; }
.feedback, .description { display: block; font-size: 24rpx; line-height: 36rpx; color: $app-text-secondary; margin-bottom: 24rpx; }
.status-tag { color: $app-primary; font-size: 24rpx; }
.controls { display: flex; flex-wrap: wrap; gap: 16rpx; margin: 24rpx 0; }
.controls button { margin: 0; font-size: 24rpx; }
.controls label { display: flex; align-items: center; font-size: 24rpx; }
</style>
