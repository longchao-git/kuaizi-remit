<template>
  <view class="body kyc">
    <text class="heading">{{ heading }}</text>
    <text v-if="error" class="description offer" role="alert">{{ error }}</text>
    <view v-if="status?.registered" class="card status-card">
      <view class="line"><text>身份验证</text><text>{{ idvLabel }}</text></view>
      <view class="line"><text>汇款权限</text><text>{{ status.remittance_enabled ? '已开通' : '未开通' }}</text></view>
    </view>
    <button v-if="status && (!status.registered || status.can_start_idv)" class="primary" :disabled="busy" @tap="start">
      前往身份验证
    </button>
    <button v-if="url" class="inline-action accent" :disabled="busy" @tap="launch">打开雅本验证页</button>
    <button v-if="status?.can_submit_profile && !status?.kyc_passed && status?.profile_status_code !== '3002'"
      class="inline-action accent" :disabled="busy" @tap="profileOpen = !profileOpen">
      {{ profileOpen ? '收起身份资料' : '填写身份资料' }}
    </button>
    <view v-if="profileOpen" class="card status-card profile-form">
      <input v-model="profile.first_name" placeholder="名" maxlength="64" />
      <input v-model="profile.last_name" placeholder="姓" maxlength="64" />
      <input v-model="profile.nationality" placeholder="国籍代码，如 CN" maxlength="2" />
      <picker :range="documentTypes" range-key="label" :disabled="busy" @change="selectDocument">
        <view class="profile-option">{{ documentTypes.find(item => item.value === profile.id_type)?.label ?? '请选择证件类型' }}</view>
      </picker>
      <input v-model="profile.id_number" placeholder="证件号码" maxlength="64" />
      <picker mode="date" :value="profile.expiry_date" :disabled="busy" @change="profile.expiry_date = String($event.detail.value)">
        <view class="profile-option">{{ profile.expiry_date || '证件有效期至' }}</view>
      </picker>
      <picker mode="date" :value="profile.birthday" :disabled="busy" @change="profile.birthday = String($event.detail.value)">
        <view class="profile-option">{{ profile.birthday || '出生日期' }}</view>
      </picker>
      <picker :range="genders" range-key="label" :disabled="busy" @change="selectGender">
        <view class="profile-option">{{ genders.find(item => item.value === profile.gender)?.label ?? '请选择性别' }}</view>
      </picker>
      <input v-model="profile.address.country" placeholder="居住国家代码，如 ES" maxlength="2" />
      <input v-model="profile.address.address_line1" placeholder="居住地址" maxlength="64" />
      <input v-model="profile.address.address_line2" placeholder="补充地址（选填）" maxlength="64" />
      <input v-model="profile.address.post_code" placeholder="邮政编码" maxlength="64" />
      <input v-model="profile.address.city" placeholder="城市" maxlength="64" />
      <button class="primary" :disabled="busy" @tap="submitProfile">提交身份资料</button>
    </view>
    <button class="inline-action accent" :disabled="busy" @tap="refresh">{{ busy ? '处理中…' : '查询认证进度' }}</button>
  </view>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import request, { UnauthorizedError } from '@/request/http';

interface KycStatus {
  registered: boolean;
  can_start_idv?: boolean;
  can_submit_profile?: boolean;
  kyc_passed?: boolean;
  remittance_enabled?: boolean;
  kyc_status_code?: string;
  idv_status_code?: string;
  profile_status_code?: string;
}

const props = defineProps<{ pageVisible: boolean }>();
const emit = defineEmits<{ expired: [] }>();
const status = ref<KycStatus>();
const url = ref('');
const busy = ref(false);
const error = ref('');
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
const kycLabels: Record<string, string> = {
  '5001': '待认证', '5002': '审核中', '5003': '需更新资料', '5004': '认证通过', '5005': '认证未通过',
};
const idvLabels: Record<string, string> = {
  '2001': '待验证', '2002': '待重新验证', '2003': '验证中', '2004': '资料已提交', '2005': '需重新提交',
  '2006': '身份验证通过', '2007': '验证未通过', '2008': '验证已过期', '2009': '验证未完成', '2010': '人工审核中',
};
const heading = computed(() => status.value ? (kycLabels[status.value.kyc_status_code ?? ''] ?? '身份认证') : '身份认证');
const idvLabel = computed(() => idvLabels[status.value?.idv_status_code ?? ''] ?? '待查询');

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

function start() {
  void run(async () => {
    const session = await request.post<{ url: string }>('app/kyc/idv', {}, options);
    if (!session.url.startsWith('https://')) throw new Error('未获取到安全的验证地址');
    url.value = session.url;
  });
}

function refresh() {
  void run(async () => {
    status.value = await request.get<KycStatus>('app/kyc/status', {}, options);
    if (status.value.registered && !status.value.can_start_idv) url.value = '';
  });
}

function launch() {
  if (!url.value) return;
  // #ifdef APP-PLUS
  plus.runtime.openURL(url.value, () => { error.value = '无法打开验证页面，请重试'; });
  // #endif
  // #ifdef H5
  const link = document.createElement('a');
  link.href = url.value;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  link.click();
  // #endif
}

function selectDocument(event: { detail: { value: string | number } }) {
  profile.id_type = documentTypes[Number(event.detail.value)]?.value ?? '';
}

function selectGender(event: { detail: { value: string | number } }) {
  profile.gender = genders[Number(event.detail.value)]?.value ?? '';
}

function submitProfile() {
  void run(async () => {
    const { address, ...identity } = profile;
    const required = [...Object.values(identity), address.country, address.address_line1, address.post_code, address.city];
    if (required.some(value => !value.trim())) throw new Error('请填写全部必填的身份资料');
    if (!/^[a-z]{2}$/i.test(identity.nationality) || !/^[a-z]{2}$/i.test(address.country)) {
      throw new Error('国籍和居住国家请填写两位字母代码');
    }
    status.value = await request.post<KycStatus>('app/kyc/profile', {
      ...identity,
      nationality: identity.nationality.toUpperCase(),
      address: { ...address, country: address.country.toUpperCase() },
    }, options);
    Object.assign(profile, newProfile());
    profileOpen.value = false;
    uni.showToast({ title: '已提交身份资料', icon: 'none' });
  });
}

watch(() => props.pageVisible, (visible) => { if (visible) refresh(); });
onMounted(refresh);
</script>

<style scoped>
.kyc { display: grid; gap: 20rpx; }
.status-card { display: grid; gap: 14rpx; padding: 24rpx; }
.profile-form input, .profile-option {
  min-height: 74rpx;
  box-sizing: border-box;
  padding: 16rpx;
  border: 1rpx solid #e1e1e7;
  border-radius: 14rpx;
}
</style>
