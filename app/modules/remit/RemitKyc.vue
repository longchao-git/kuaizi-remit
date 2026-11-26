<template>
  <view class="body kyc">
    <text class="heading">{{ heading }}</text>
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <text v-if="status?.guidance" class="description">{{ status.guidance }}</text>
    <view v-if="status?.registered" class="card status-card">
      <view class="line"><text>身份验证</text><text>{{ idvLabel }}</text></view>
      <view class="line"><text>身份资料</text><text>{{ profileLabel }}</text></view>
      <view class="line"><text>汇款权限</text><text>{{ status.remittance_enabled ? '已开通' : '未开通' }}</text></view>
    </view>
    <button v-if="status && (!status.registered || status.can_start_idv)" class="primary" :disabled="busy" @tap="start">
      前往身份验证
    </button>
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
    <button class="inline-action accent" :disabled="busy" @tap="refresh">{{ busy ? '处理中…' : '查询认证进度' }}</button>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import request, { UnauthorizedError, ValidationError } from '@/request/http';

interface KycStatus {
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
const emit = defineEmits<{ expired: [] }>();
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
const heading = computed(() => status.value ? (kycLabels[status.value.kyc_status_code ?? ''] ?? '身份认证') : '身份认证');
const idvLabel = computed(() => idvLabels[status.value?.idv_status_code ?? ''] ?? '待查询');
const profileLabels: Record<string, string> = { '3001': '待提交', '3002': '已批准', '3003': '未获批准', '3004': '需更新资料' };
const profileLabel = computed(() => profileLabels[status.value?.profile_status_code ?? ''] ?? '待查询');
const canEditProfile = computed(() => Boolean(
  status.value?.can_submit_profile && !status.value.kyc_passed && status.value.profile_status_code !== '3002',
));

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
    const session = await request.post<{ url: string }>('app/kyc/idv', {}, options);
    if (!active) return;
    if (!session.url.startsWith('https://')) throw new Error('未获取到安全的验证地址');
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
.kyc { display: grid; gap: 20rpx; }
.field-errors { display: grid; gap: 10rpx; color: #b23b3b; font-size: 26rpx; }
.profile-option.invalid { border-color: #b23b3b; }
.status-card { display: grid; gap: 14rpx; padding: 24rpx; }
.profile-form input, .profile-option {
  min-height: 74rpx;
  box-sizing: border-box;
  padding: 16rpx;
  border: 1rpx solid #e1e1e7;
  border-radius: 14rpx;
}
</style>
