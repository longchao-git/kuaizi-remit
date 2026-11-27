<template>
  <view class="body kyc">
    <view v-if="status" class="kyc-hero">
      <view class="kyc-hero-icon" :class="`kyc-hero-${heroTone}`" aria-hidden="true">
        <image v-if="heroTone === 'identity'" src="/static/remit/status-identity.svg" mode="aspectFit" />
        <image v-else-if="heroTone === 'pending'" src="/static/remit/status-refresh.svg" mode="aspectFit" />
        <view v-else-if="heroTone === 'success'" class="kyc-tick" />
        <image v-else src="/static/remit/status-warning.svg" mode="aspectFit" />
      </view>
      <text class="heading">{{ heading }}</text>
      <text v-if="status.simulated" class="description">模拟认证，请使用测试资料。</text>
      <text v-if="status.guidance" class="description">{{ status.guidance }}</text>
      <text v-else-if="showIntroduction" class="description">请准备有效证件，并确认由本人完成验证。</text>
      <text v-else-if="heroTone === 'pending'" class="description">资料审核期间可以离开此页面。</text>
      <text v-else-if="heroTone === 'success'" class="description">{{ status.remittance_enabled ? '你现在可以继续汇款。' : '可在下方查看汇款权限。' }}</text>
      <text v-else-if="heroTone === 'failure' && status.can_start_idv" class="description">请检查证件资料后重新验证。</text>
    </view>
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <template v-if="showIntroduction">
      <view v-for="(step, index) in introductionSteps" :key="step.title" class="card kyc-step">
        <text class="kyc-step-number">{{ index + 1 }}</text>
        <view><text class="block">{{ step.title }}</text><text class="kyc-step-detail">{{ step.detail }}</text></view>
      </view>
      <text class="kyc-notice">认证资料用于身份核验，请按验证页提示提交。</text>
    </template>
    <view v-else-if="status?.registered && !showRetryTips" class="card status-card">
      <view class="line"><text>身份验证</text><text :class="{ 'status-ok': status.kyc_passed }">{{ idvLabel }}</text></view>
      <view class="line"><text>身份资料</text><text :class="{ 'status-ok': status.profile_status_code === '3002' }">{{ profileLabel }}</text></view>
      <view class="line"><text>汇款权限</text><text :class="{ 'status-ok': status.remittance_enabled }">{{ status.remittance_enabled ? '已开通' : '未开通' }}</text></view>
    </view>
    <text v-if="heroTone === 'pending'" class="kyc-notice">你可以随时回来查询认证进度。</text>
    <view v-if="showRetryTips" class="card kyc-retry">
      <text class="kyc-retry-title">重新拍摄时请注意</text>
      <view v-for="(tip, index) in retryTips" :key="tip" class="kyc-retry-tip">
        <text class="kyc-retry-number">{{ index + 1 }}</text>
        <text>{{ tip }}</text>
      </view>
    </view>
    <button v-if="url" class="inline-action accent" :disabled="busy" @tap="launch">打开雅本验证页</button>
    <button v-if="canEditProfile"
      class="inline-action accent" :disabled="busy" @tap="profileOpen = !profileOpen">
      {{ profileOpen ? '收起身份资料' : '填写身份资料' }}
    </button>
    <view v-if="canEditProfile && profileOpen" class="card status-card profile-form">
      <view v-if="Object.keys(fieldErrors).length" role="alert" class="field-errors">
        <text v-for="(message, field) in fieldErrors" :key="field">{{ fieldLabels[field] || '身份资料' }}：{{ message }}</text>
      </view>
      <input v-model="profile.first_name" placeholder="名" maxlength="64" :disabled="busy" />
      <input v-model="profile.last_name" placeholder="姓" maxlength="64" :disabled="busy" />
      <input v-model="profile.nationality" placeholder="国籍代码，如 CN" maxlength="2" :disabled="busy" />
      <picker :range="documentTypes" range-key="label" :disabled="busy" @change="selectDocument">
        <view class="profile-option">{{ documentTypes.find(item => item.value === profile.id_type)?.label ?? '请选择证件类型' }}</view>
      </picker>
      <input v-model="profile.id_number" placeholder="证件号码" maxlength="64" :disabled="busy" />
      <picker mode="date" :value="profile.expiry_date" :start="status?.server_date" :disabled="busy" @change="profile.expiry_date = String($event.detail.value)">
        <view class="profile-option" :class="{ invalid: fieldErrors.expiry_date }">{{ profile.expiry_date || '证件有效期至' }}</view>
      </picker>
      <picker mode="date" :value="profile.birthday" :end="latestBirthday" :disabled="busy" @change="profile.birthday = String($event.detail.value)">
        <view class="profile-option" :class="{ invalid: fieldErrors.birthday }">{{ profile.birthday || '出生日期' }}</view>
      </picker>
      <picker :range="genders" range-key="label" :disabled="busy" @change="selectGender">
        <view class="profile-option">{{ genders.find(item => item.value === profile.gender)?.label ?? '请选择性别' }}</view>
      </picker>
      <input v-model="profile.address.country" placeholder="居住国家代码，如 ES" maxlength="2" :disabled="busy" />
      <input v-model="profile.address.address_line1" placeholder="居住地址" maxlength="64" :disabled="busy" />
      <input v-model="profile.address.address_line2" placeholder="补充地址（选填）" maxlength="64" :disabled="busy" />
      <input v-model="profile.address.post_code" placeholder="邮政编码" maxlength="64" :disabled="busy" />
      <input v-model="profile.address.city" placeholder="城市" maxlength="64" :disabled="busy" />
      <button class="primary" :disabled="busy" @tap="submitProfile">提交身份资料</button>
    </view>
    <button v-if="heroTone === 'success' && status?.remittance_enabled"
      class="inline-action accent kyc-status-refresh" :disabled="busy" @tap="refresh">
      {{ busy ? '处理中…' : '查询认证进度' }}
    </button>
    <view class="kyc-actions" :class="{ 'is-approved': heroTone === 'success' && status?.remittance_enabled }">
      <button v-if="status && (!status.registered || status.can_start_idv)" class="primary" :disabled="busy" @tap="start">
        {{ heroTone === 'failure' ? '重新认证' : '开始认证' }}
      </button>
      <button v-else-if="heroTone === 'success' && status?.remittance_enabled" class="primary" @tap="$emit('home')">继续汇款</button>
      <button v-else-if="heroTone === 'pending'" class="secondary kyc-return" @tap="$emit('home')">返回首页</button>
      <button v-if="heroTone === 'pending' || heroTone === 'failure'" class="secondary" @tap="$emit('support')">联系客服</button>
      <button v-if="heroTone !== 'success' || !status?.remittance_enabled"
        class="inline-action accent" :disabled="busy" @tap="refresh">{{ busy ? '处理中…' : '查询认证进度' }}</button>
    </view>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import request, { UnauthorizedError, ValidationError } from '@/request/http';

interface KycStatus {
  simulated?: boolean;
  registered: boolean;
  can_start_idv?: boolean;
  can_submit_profile?: boolean;
  kyc_passed?: boolean;
  remittance_enabled?: boolean;
  kyc_status_code?: string;
  idv_status_code?: string;
  profile_status_code?: string;
  guidance?: string | null;
  server_date?: string;
}

const props = defineProps<{ pageVisible: boolean }>();
const emit = defineEmits<{ expired: []; home: []; support: [] }>();
const status = ref<KycStatus>();
const url = ref('');
const busy = ref(false);
const error = ref('');
const fieldErrors = ref<Record<string, string>>({});
const fieldLabels: Record<string, string> = {
  first_name: '名', last_name: '姓', nationality: '国籍', id_type: '证件类型', id_number: '证件号码',
  expiry_date: '证件有效期', birthday: '出生日期', gender: '性别', 'address.country': '居住国家',
  'address.address_line1': '居住地址', 'address.address_line2': '补充地址', 'address.post_code': '邮政编码', 'address.city': '城市',
};
const latestBirthday = computed(() => {
  if (!status.value?.server_date) return undefined;
  const date = new Date(status.value.server_date + 'T00:00:00Z');
  date.setUTCDate(date.getUTCDate() - 1);
  return date.toISOString().slice(0, 10);
});
const profileOpen = ref(false);
const newProfile = () => ({
  first_name: '', last_name: '', nationality: '', id_type: '', id_number: '', expiry_date: '', birthday: '', gender: '',
  address: { country: '', address_line1: '', address_line2: '', post_code: '', city: '' },
});
const profile = reactive(newProfile());
const documentTypes = [
  { label: '身份证', value: 'national_id' },
  { label: '护照', value: 'passport' },
  { label: '居留许可', value: 'residence_permit' },
];
const genders = [{ label: '男', value: 'M' }, { label: '女', value: 'F' }];
const options = { handleUnauthorized: false };
let active = true;
let awaitingReturn = false;
let refreshQueued = false;
const kycLabels: Record<string, string> = {
  '5001': '待认证', '5002': '审核中', '5003': '需更新资料', '5004': '认证通过', '5005': '认证未通过',
};
const idvLabels: Record<string, string> = {
  '2001': '待验证', '2002': '待重新验证', '2003': '验证中', '2004': '资料已提交', '2005': '需重新提交',
  '2006': '身份验证通过', '2007': '验证未通过', '2008': '验证已过期', '2009': '验证未完成', '2010': '人工审核中',
};
const heading = computed(() => {
  if (status.value?.simulated && status.value.can_submit_profile) return '待提交资料';
  if (showIntroduction.value) return '完成认证即可汇款';
  if (heroTone.value === 'pending') return '资料审核中';
  if (heroTone.value === 'success') return '认证已通过';
  if (status.value?.kyc_status_code === '5005') return '暂时无法完成认证';
  return kycLabels[status.value?.kyc_status_code ?? ''] ?? '身份认证';
});
const heroTone = computed(() => {
  switch (status.value?.kyc_status_code) {
    case '5002': return 'pending';
    case '5004': return 'success';
    case '5003':
    case '5005': return 'failure';
    default: return 'identity';
  }
});
const idvLabel = computed(() => idvLabels[status.value?.idv_status_code ?? ''] ?? '待查询');
const profileLabels: Record<string, string> = { '3001': '待提交', '3002': '已批准', '3003': '未获批准', '3004': '需更新资料' };
const profileLabel = computed(() => profileLabels[status.value?.profile_status_code ?? ''] ?? '待查询');
const canEditProfile = computed(() => Boolean(
  status.value?.can_submit_profile && !status.value.kyc_passed && status.value.profile_status_code !== '3002',
));
const showIntroduction = computed(() => heroTone.value === 'identity' && !canEditProfile.value
  && Boolean(status.value && (!status.value.registered || status.value.can_start_idv)));
const showRetryTips = computed(() => heroTone.value === 'failure' && status.value?.can_start_idv);
const introductionSteps = [
  { title: '拍摄有效证件', detail: '按验证页提示选择证件和拍摄方式' },
  { title: '完成人脸识别', detail: '确认由本人操作' },
  { title: '等待系统审核', detail: '审核结果可在此页面查询' },
];
const retryTips = ['在光线均匀的环境中拍摄', '完整露出证件四角，避免遮挡', '避开反光，确保证件文字清晰'];

async function run(action: () => Promise<void>) {
  if (!active || busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    await action();
  } catch (reason) {
    if (!active) return;
    if (reason instanceof UnauthorizedError) emit('expired');
    else if (reason instanceof ValidationError) {
      fieldErrors.value = Object.fromEntries(Object.keys(reason.fields).map(field => [field, '请检查该项的格式和范围']));
      error.value = '身份资料未提交，请检查标出的项目';
    }
    else error.value = reason instanceof Error ? reason.message : '操作失败，请重试';
  } finally {
    busy.value = false;
    if (active && refreshQueued && props.pageVisible) {
      refreshQueued = false;
      refresh();
    }
  }
}

function start() {
  void run(async () => {
    const session = await request.post<{ url?: string; simulated?: boolean }>('app/kyc/idv', {}, options);
    if (!active) return;
    if (session.simulated) {
      url.value = '';
      refreshQueued = true;
      return;
    }
    if (!session.url?.startsWith('https://')) throw new Error('未获取到安全的验证地址');
    url.value = session.url;
  });
}

function refresh() {
  if (!active || !props.pageVisible) return;
  if (busy.value) {
    refreshQueued = true;
    return;
  }
  refreshQueued = false;
  void run(async () => {
    const latest = await request.get<KycStatus>('app/kyc/status', {}, options);
    if (!active) return;
    status.value = latest;
    if (status.value.registered && !status.value.can_start_idv) url.value = '';
  });
}

function launch() {
  if (!url.value || !active || busy.value) return;
  awaitingReturn = true;
  // #ifdef APP-PLUS
  plus.runtime.openURL(url.value, () => {
    awaitingReturn = false;
    if (active) error.value = '无法打开验证页面，请重试';
  });
  // #endif
  // #ifdef H5
  const link = document.createElement('a');
  link.href = url.value;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  link.click();
  // #endif
}

// #ifdef H5
function returnedFromBrowser() {
  if (document.visibilityState === 'visible' && awaitingReturn && props.pageVisible) {
    awaitingReturn = false;
    refresh();
  }
}
// #endif

function selectDocument(event: { detail: { value: string | number } }) {
  profile.id_type = documentTypes[Number(event.detail.value)]?.value ?? '';
}

function selectGender(event: { detail: { value: string | number } }) {
  profile.gender = genders[Number(event.detail.value)]?.value ?? '';
}

function submitProfile() {
  if (!canEditProfile.value) return;
  fieldErrors.value = {};
  void run(async () => {
    const { address, ...identity } = profile;
    const required = [...Object.values(identity), address.country, address.address_line1, address.post_code, address.city];
    if (required.some(value => !value.trim())) throw new Error('请填写全部必填的身份资料');
    if (!/^[a-z]{2}$/i.test(identity.nationality) || !/^[a-z]{2}$/i.test(address.country)) {
      throw new Error('国籍和居住国家请填写两位字母代码');
    }
    const validDate = (value: string) => /^\d{4}-\d{2}-\d{2}$/.test(value)
      && !Number.isNaN(Date.parse(value + 'T00:00:00Z'))
      && new Date(value + 'T00:00:00Z').toISOString().slice(0, 10) === value;
    const today = status.value?.server_date;
    if (!validDate(identity.expiry_date) || (today && identity.expiry_date < today)) {
      fieldErrors.value.expiry_date = '请选择尚未到期的有效日期';
    }
    if (!validDate(identity.birthday) || (today && identity.birthday >= today)) {
      fieldErrors.value.birthday = '出生日期必须早于今天';
    }
    if (Object.keys(fieldErrors.value).length) throw new Error('请核对证件有效期和出生日期');
    const latest = await request.post<KycStatus>('app/kyc/profile', {
      ...identity,
      nationality: identity.nationality.toUpperCase(),
      address: { ...address, country: address.country.toUpperCase() },
    }, options);
    if (!active) return;
    status.value = latest;
    Object.assign(profile, newProfile());
    profileOpen.value = false;
    fieldErrors.value = {};
    uni.showToast({ title: '已提交身份资料', icon: 'none' });
  });
}

watch(canEditProfile, (allowed) => {
  if (!allowed) {
    profileOpen.value = false;
    fieldErrors.value = {};
    Object.assign(profile, newProfile());
  }
});
watch(() => props.pageVisible, (visible) => {
  if (visible) {
    awaitingReturn = false;
    refresh();
  }
});
onMounted(() => {
  // #ifdef H5
  document.addEventListener('visibilitychange', returnedFromBrowser);
  // #endif
  refresh();
});
onUnmounted(() => {
  active = false;
  awaitingReturn = false;
  refreshQueued = false;
  // #ifdef H5
  document.removeEventListener('visibilitychange', returnedFromBrowser);
  // #endif
});
</script>

<style scoped>
.kyc { display: flex; flex: 1; flex-direction: column; gap: 20rpx; padding-inline: 32rpx; }
.kyc-hero {
  display: grid;
  justify-items: center;
  gap: 16rpx;
  padding: 40rpx 0 16rpx;
  text-align: center;
}
.kyc-hero .description {
  margin-top: 0;
}
.kyc-hero-icon {
  display: grid;
  place-items: center;
  width: 240rpx;
  height: 240rpx;
  margin-bottom: 16rpx;
  border-radius: 78rpx;
  background: #ece9ff;
}
.kyc-hero-icon image {
  width: 110rpx;
  height: 110rpx;
}
.kyc-hero-success {
  color: #47946d;
  background: #daf3e6;
}
.kyc-hero-failure {
  background: #ffd7e0;
}
.kyc-tick {
  box-sizing: border-box;
  width: 36rpx;
  height: 72rpx;
  margin-top: -18rpx;
  border: solid currentColor;
  border-width: 0 10rpx 10rpx 0;
  border-radius: 5rpx;
  transform: rotate(40deg);
}
.kyc-step {
  display: flex;
  min-height: 128rpx;
  align-items: center;
  gap: 24rpx;
  padding: 16rpx 22rpx;
}
.kyc-step-number {
  display: grid;
  place-items: center;
  flex: 0 0 82rpx;
  height: 82rpx;
  border-radius: 50%;
  background: #eeebff;
  color: var(--remit-accent);
  font-size: 38rpx;
  font-weight: 700;
}
.kyc-step-detail {
  display: block;
  margin-top: 8rpx;
  color: #777;
  font-size: 23rpx;
}
.kyc-notice {
  padding: 22rpx;
  border-radius: 24rpx;
  background: #edf8f2;
  color: #367d5c;
  font-size: 24rpx;
}
.kyc-retry {
  display: grid;
  gap: 16rpx;
  padding: 28rpx 32rpx;
}
.kyc-retry-title { font-weight: 600; }
.kyc-retry-tip {
  display: flex;
  align-items: center;
  gap: 16rpx;
  color: #666;
  font-size: 26rpx;
}
.kyc-retry-number {
  display: grid;
  place-items: center;
  flex: 0 0 37rpx;
  height: 37rpx;
  border-radius: 50%;
  background: var(--remit-accent);
  color: #fff;
  font-size: 23rpx;
}
.status-ok { color: #367d5c; }
.kyc-actions {
  display: grid;
  gap: 18rpx;
  margin-top: auto;
  padding: 32rpx 0 max(50rpx, calc(16rpx + env(safe-area-inset-bottom)));
}
.kyc-actions.is-approved { padding-bottom: max(70rpx, calc(24rpx + env(safe-area-inset-bottom))); }
.kyc-actions.is-approved .primary { min-height: 110rpx; }
.kyc-status-refresh { align-self: flex-end; }
.kyc-actions .kyc-return {
  color: var(--remit-accent);
  background: #eeebff;
  border-color: transparent;
}
.field-errors { display: grid; gap: 10rpx; color: #b23b3b; font-size: 26rpx; }
.profile-option.invalid { border-color: #b23b3b; }
.status-card { display: grid; gap: 28rpx; padding: 32rpx; }
.profile-form input, .profile-option {
  min-height: 74rpx;
  box-sizing: border-box;
  padding: 16rpx;
  border: 1rpx solid #e1e1e7;
  border-radius: 14rpx;
}
</style>
