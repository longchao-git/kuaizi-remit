<template>
  <view class="body help-content">
    <view class="help-card">
      <view class="line">
        <view>
          <text class="help-title">汇款遇到问题？</text>
          <text class="help-summary">查询汇款进度、费用和补件问题</text>
        </view>
        <image class="help-icon" src="/static/remit/support.svg" mode="aspectFit" />
      </view>
      <button class="primary inverted" @tap="$emit('contact')">联系筷子闪汇客服</button>
    </view>

    <view class="card help-search">
      <view class="help-search-icon" aria-hidden="true" />
      <input v-model="search" placeholder="搜索问题，例如“多久到账”" aria-label="搜索常见问题" />
    </view>
    <text class="help-label">常见问题</text>
    <text v-if="!questions.length" class="description">没有匹配的问题</text>
    <view v-for="question in questions" :key="question.title" class="card help-question">
      <button
        class="line help-question-button" :aria-expanded="activeQuestion === question.title"
        @tap="activeQuestion = activeQuestion === question.title ? '' : question.title"
      >
        <text class="question-icon">{{ question.icon }}</text>
        <view class="question-copy">
          <text class="question-title">{{ question.title }}</text>
          <text v-if="activeQuestion !== question.title" class="question-summary">{{ question.summary }}</text>
        </view>
        <image class="chevron" src="/static/remit/chevron.svg" mode="aspectFit" />
      </button>
      <text v-if="activeQuestion === question.title" class="question-answer">{{ question.answer }}</text>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue';
import { faq } from './previewContent';

defineEmits<{ contact: [] }>();
const search = ref('');
const activeQuestion = ref('');
const questions = computed(() => faq.filter(question => (question.title + question.summary + question.answer).includes(search.value.trim())));
</script>

<style scoped>
.help-content {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  align-content: start;
  gap: 22rpx;
}
.help-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 26rpx;
  padding: 36rpx 40rpx 40rpx;
  border-radius: 46rpx;
  color: #fff;
  background: linear-gradient(154deg, #6f63f6, #4e40e4);
}
.help-title,
.help-summary {
  display: block;
}
.help-title {
  font-size: 38rpx;
  font-weight: 700;
}
.help-summary {
  margin-top: 10rpx;
  color: #eeebff;
  font-size: 24rpx;
}
.help-icon {
  flex-shrink: 0;
  width: 72rpx;
  height: 72rpx;
}
.help-card .primary {
  border-radius: 32rpx;
  font-size: 28rpx;
  font-weight: 700;
}
.help-search {
  display: flex;
  align-items: center;
  gap: 18rpx;
  min-height: 110rpx;
  padding: 14rpx 30rpx;
}
.help-search-icon {
  position: relative;
  flex: 0 0 14rpx;
  height: 14rpx;
  border: 3rpx solid #6d7180;
  border-radius: 50%;
}
.help-search-icon::after {
  position: absolute;
  width: 8rpx;
  height: 3rpx;
  right: -6rpx;
  bottom: -3rpx;
  transform: rotate(45deg);
  background: #6d7180;
  content: "";
}
.help-search input {
  flex: 1;
  min-width: 0;
  height: 56rpx;
}
.help-label {
  padding-top: 12rpx;
  color: #6d7180;
  font-size: 24rpx;
}
.help-question {
  padding: 26rpx 30rpx;
  border-color: #e9e9f0;
  border-radius: 36rpx;
  box-shadow: 0 2rpx 5rpx rgba(31, 35, 48, 0.035);
}
.help-question-button {
  width: 100%;
  padding: 0;
  color: #242733;
  background: transparent;
  font-size: 28rpx;
  font-weight: 700;
  line-height: 1.35;
  text-align: left;
}
.question-icon {
  display: grid;
  place-items: center;
  flex: 0 0 88rpx;
  height: 88rpx;
  border-radius: 32rpx;
  color: #5243cb;
  background: #eeebff;
  font-size: 38rpx;
}
.question-copy {
  display: grid;
  flex: 1;
  gap: 5rpx;
  min-width: 0;
}
.question-title,
.question-summary {
  display: block;
  overflow-wrap: anywhere;
}
.question-summary {
  overflow: hidden;
  color: #6d7180;
  font-size: 22rpx;
  font-weight: 400;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.question-answer {
  display: block;
  margin: 12rpx 0 6rpx 104rpx;
  color: #5d6170;
  font-size: 25rpx;
  line-height: 1.5;
}
.help-question-button .chevron {
  flex-shrink: 0;
}
</style>
