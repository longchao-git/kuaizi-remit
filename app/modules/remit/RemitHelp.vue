<template>
  <view class="body help-content">
    <view class="help-card">
      <view class="line">
        <text class="help-title">汇款遇到问题？</text>
        <image class="help-icon" src="/static/remit/support.svg" mode="aspectFit" />
      </view>
      <button class="primary inverted" @tap="$emit('contact')">联系筷子闪汇客服</button>
    </view>

    <view class="card help-search">
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
        <text class="question-title">{{ question.title }}</text>
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
const questions = computed(() => faq.filter(question => (question.title + question.answer).includes(search.value.trim())));
</script>

<style scoped>
.help-content {
  display: grid;
  align-content: start;
  gap: 16rpx;
}
.help-card {
  display: grid;
  gap: 20rpx;
  padding: 28rpx;
  border-radius: 30rpx;
  color: #fff;
  background: linear-gradient(145deg, #7061f7, #5143e4);
}
.help-title {
  font-size: 34rpx;
  font-weight: 700;
}
.help-icon {
  width: 60rpx;
  height: 60rpx;
}
.help-search {
  padding: 14rpx 24rpx;
}
.help-search input {
  height: 56rpx;
}
.help-label {
  padding-top: 12rpx;
  color: #6d7180;
  font-size: 24rpx;
}
.help-question {
  padding: 14rpx 18rpx;
}
.help-question-button {
  width: 100%;
  padding: 0;
  color: #242733;
  background: transparent;
  font-size: 28rpx;
  font-weight: 600;
  text-align: left;
}
.question-icon {
  display: grid;
  place-items: center;
  flex: 0 0 56rpx;
  height: 56rpx;
  border-radius: 18rpx;
  color: #5243cb;
  background: #eeebff;
}
.question-title {
  flex: 1;
  min-width: 0;
}
.question-answer {
  display: block;
  margin: 12rpx 0 6rpx 72rpx;
  color: #5d6170;
  font-size: 25rpx;
  line-height: 1.5;
}
</style>
