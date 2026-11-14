<template>
  <div ref="chartRef" class="my-echarts" :style="chartStyle"></div>
</template>

<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import * as echarts from 'echarts'
import type { ECharts, EChartsCoreOption, SetOptionOpts } from 'echarts'

interface Props {
  options: EChartsCoreOption
  width?: string
  height?: string
  theme?: string | object
  autoresize?: boolean
  loading?: boolean
  loadingText?: string
  initOptions?: Parameters<typeof echarts.init>[2]
  setOptionOpts?: SetOptionOpts
}

const props = withDefaults(defineProps<Props>(), {
  width: '100%',
  height: '320px',
  theme: undefined,
  autoresize: true,
  loading: false,
  loadingText: '',
  initOptions: undefined,
  setOptionOpts: undefined
})

const chartRef = ref<HTMLDivElement | null>(null)
let chartInstance: ECharts | null = null
let resizeObserver: ResizeObserver | null = null

const chartStyle = computed(() => ({
  width: props.width,
  height: props.height
}))

const initChart = () => {
  if (!chartRef.value) return
  chartInstance?.dispose()
  chartInstance = echarts.init(chartRef.value, props.theme, props.initOptions)
  renderChart()
}

const renderChart = () => {
  if (!chartInstance) return
  chartInstance.setOption(props.options || {}, props.setOptionOpts)
  if (props.loading) {
    chartInstance.showLoading('default', { text: props.loadingText || 'Loading...' })
  } else {
    chartInstance.hideLoading()
  }
}

const resizeChart = () => {
  chartInstance?.resize()
}

const bindResize = () => {
  if (!props.autoresize || !chartRef.value) return
  resizeObserver?.disconnect()
  resizeObserver = new ResizeObserver(() => {
    resizeChart()
  })
  resizeObserver.observe(chartRef.value)
}

watch(
  () => props.options,
  () => {
    renderChart()
  },
  { deep: true }
)

watch(
  () => props.loading,
  () => {
    renderChart()
  }
)

watch(
  () => [props.width, props.height],
  async () => {
    await nextTick()
    resizeChart()
  }
)

watch(
  () => [props.theme, props.initOptions],
  async () => {
    await nextTick()
    initChart()
    bindResize()
  },
  { deep: true }
)

onMounted(async () => {
  await nextTick()
  initChart()
  bindResize()
})

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
  resizeObserver = null
  chartInstance?.dispose()
  chartInstance = null
})

defineExpose({
  getChartInstance: () => chartInstance,
  resize: resizeChart
})
</script>

<style scoped>
.my-echarts {
  display: block;
}
</style>
