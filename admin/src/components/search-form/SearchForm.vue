<template>
  <div class="containerSearch">
    <el-form ref="formRef" :model="formData" inline>
      <el-row :gutter="gutter">
        <template v-for="(item, index) in options" :key="item.key">
          <el-col
            v-if="!item.isHidden"
            class="mb16"
            :span="item.col ?? col"
            :xs="item.xs ?? xs"
            :sm="item.sm ?? sm"
            :md="item.md ?? md"
            :lg="item.lg ?? item.col ?? col"
            :xl="item.xl ?? item.col ?? col"
          >
            <el-form-item :label="item.label" :prop="item.key" :label-width="item.labelWidth || labelWidth || '100px'">
              <template v-if="item.type === 'input'">
                <el-input
                  v-model="formData[item.key]"
                  :placeholder="item.placeholder || '请输入'"
                  clearable
                  :style="{ width: resolveControlWidth(item) }"
                  @keyup.enter="handleSearch"
                />
              </template>

              <template v-else-if="item.type === 'twoInput'">
                <el-input
                  v-model="formData[item.keyOne]"
                  :placeholder="item.placeholder || '请输入'"
                  clearable
                  class="search-form__range-input"
                  @keyup.enter="handleSearch"
                />
                <span class="search-form__range-separator">-</span>
                <el-input
                  v-model="formData[item.keyTwo]"
                  :placeholder="item.placeholder || '请输入'"
                  clearable
                  class="search-form__range-input"
                  @keyup.enter="handleSearch"
                />
              </template>

              <template v-else-if="item.type === 'select'">
                <el-select
                  v-model="formData[item.key]"
                  clearable
                  :filterable="item.filterable"
                  :multiple="item.multiple"
                  :collapse-tags="item.collapseTags"
                  :placeholder="item.placeholder || '请选择'"
                  :style="{ width: resolveControlWidth(item) }"
                >
                  <el-option
                    v-for="child in item.options"
                    :key="String(child.value)"
                    :label="child.label"
                    :value="child.value"
                    :disabled="child.disabled"
                  />
                </el-select>
              </template>

              <template v-else-if="item.type === 'cascader'">
                <el-cascader
                  v-model="formData[item.key]"
                  :options="item.options"
                  :props="item.props"
                  :filterable="item.filterable ?? true"
                  :placeholder="item.placeholder || '请选择'"
                  clearable
                  :style="{ width: resolveControlWidth(item) }"
                />
              </template>

              <template v-else-if="item.type === 'date' || item.type === 'daterange'">
                <el-date-picker
                  v-model="formData[item.key]"
                  :type="item.type"
                  :format="item.format"
                  :value-format="resolveDateValueFormat(item)"
                  :default-time="resolveDefaultTime(item)"
                  :placeholder="item.placeholder || '请选择日期'"
                  range-separator="至"
                  start-placeholder="开始时间"
                  end-placeholder="结束时间"
                  clearable
                  :style="{ width: resolveControlWidth(item) }"
                />
              </template>

              <template v-else-if="item.type === 'selectInput'">
                <el-select
                  v-model="formData[item.selKey]"
                  :style="{ width: selWidth }"
                  :filterable="item.filterable"
                  placeholder="请选择"
                >
                  <el-option
                    v-for="child in item.options"
                    :key="String(child.value)"
                    :label="child.label"
                    :value="child.value"
                    :disabled="child.disabled"
                  />
                </el-select>
                <el-input
                  v-model="formData[item.key]"
                  :placeholder="item.placeholder || '请输入'"
                  class="input-with-select"
                  :style="{ marginLeft: '10px', width: resolveControlWidth(item) }"
                  clearable
                  @keyup.enter="handleSearch"
                />
              </template>

              <template v-else-if="item.type === 'radio'">
                <el-radio-group v-model="formData[item.key]">
                  <el-radio
                    v-for="child in item.options"
                    :key="String(child.value)"
                    :value="child.value"
                    :disabled="child.disabled"
                  >
                    {{ child.label }}
                  </el-radio>
                </el-radio-group>
              </template>

              <template v-else-if="item.type === 'custom'">
                <slot :name="item.key" v-bind="{ data: formData, key: item.key, index }" />
              </template>
            </el-form-item>
          </el-col>
        </template>
      </el-row>
    </el-form>

    <div class="search-form__actions">
      <el-button v-if="showSearch" type="primary" :loading="searching" @click="handleSearch">
        {{ searchText || '查询' }}
      </el-button>
      <el-button v-if="showReset" :class="{ 'search-form__reset--plain': resetType === 'plain' }" @click="handleReset">
        {{ resetText || '重置' }}
      </el-button>
      <slot name="btnRight" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import type { FormInstance } from 'element-plus'
import type { SearchFormDateOption, SearchFormOption } from './types'

interface SearchFormProps {
  col?: number
  xs?: number
  sm?: number
  md?: number
  gutter?: number
  labelWidth?: string
  options: SearchFormOption[]
  searchData: Record<string, unknown>
  selWidth?: string
  showSearch?: boolean
  showReset?: boolean
  searching?: boolean
  searchText?: string
  resetText?: string
  resetType?: 'default' | 'plain'
}

const props = withDefaults(defineProps<SearchFormProps>(), {
  col: 6,
  xs: 24,
  sm: 12,
  md: 8,
  gutter: 0,
  labelWidth: '',
  selWidth: '100px',
  showSearch: true,
  showReset: true,
  searching: false,
  searchText: '',
  resetText: '',
  resetType: 'plain'
})

const emit = defineEmits<{
  (e: 'search', value: Record<string, unknown>): void
  (e: 'reset'): void
}>()

const formRef = ref<FormInstance>()
const formData = reactive<Record<string, unknown>>({})
const fullDayDefaultTime: [Date, Date] = [
  new Date(2000, 0, 1, 0, 0, 0),
  new Date(2000, 0, 1, 23, 59, 59)
]

watch(
  () => props.searchData,
  value => {
    Object.keys(formData).forEach(key => delete formData[key])
    Object.assign(formData, value || {})
  },
  { immediate: true, deep: true }
)

const syncToExternal = () => {
  const externalSearchData = props.searchData
  Object.keys(externalSearchData).forEach(key => {
    if (!(key in formData)) delete externalSearchData[key]
  })
  Object.assign(externalSearchData, formData)
  return externalSearchData
}

const resolveControlWidth = (item: SearchFormOption) => {
  if (item.width) return item.width
  if (item.type === 'daterange') return '360px'
  return '240px'
}

const resolveDateValueFormat = (item: SearchFormDateOption) => {
  if (item.valueFormat) return item.valueFormat
  return item.type === 'daterange' ? 'YYYY-MM-DD HH:mm:ss' : 'YYYY-MM-DD'
}

const resolveDefaultTime = (item: SearchFormDateOption) => {
  if (item.defaultTime) return item.defaultTime
  return item.type === 'daterange' ? fullDayDefaultTime : undefined
}

const handleSearch = () => emit('search', syncToExternal())

const handleReset = () => {
  formRef.value?.resetFields()
  emit('reset')
}

defineExpose({
  getParams: () => formData,
  resetFields: () => formRef.value?.resetFields()
})
</script>

<style scoped>
.containerSearch {
  border-radius: 4px;
  background: #f7f8fa;
  padding: 30px 20px;
  margin-bottom: 20px;
}

.containerSearch :deep(.el-row) {
  width: 100%;
}

.containerSearch :deep(.el-form-item) {
  width: 95%;
  margin-bottom: 16px;
  margin-right: 0 !important;
  align-items: center;
}

.containerSearch :deep(.el-form-item__label) {
  min-height: 40px;
  display: inline-flex;
  align-items: center;
  justify-content: flex-end;
  padding-right: 12px;
  box-sizing: border-box;
}

.containerSearch :deep(.el-form-item__content) {
  min-height: 40px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
}

.containerSearch :deep(.el-radio-group) {
  min-height: 40px;
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 18px;
}

.search-form__range-input {
  width: calc(50% - 16px);
}

.search-form__range-separator {
  width: 32px;
  text-align: center;
}

.search-form__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
  margin-left: v-bind("labelWidth || '100px'");
}

.search-form__reset--plain {
  background: #eff0f3 !important;
  border-color: #eff0f3 !important;
  color: #383838 !important;
}

@media (max-width: 767px) {
  .containerSearch {
    padding: 20px 12px;
  }

  .containerSearch :deep(.el-form-item),
  .containerSearch :deep(.el-input),
  .containerSearch :deep(.el-select),
  .containerSearch :deep(.el-cascader),
  .containerSearch :deep(.el-date-editor) {
    width: 100% !important;
  }

  .search-form__actions {
    margin-left: 0;
  }
}
</style>
