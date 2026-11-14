import { computed } from 'vue'
import type { EChartsCoreOption } from 'echarts'
import { useUserStore } from '@/stores/user'
import type { MenuVO } from '@/shared/types/menu'

function countMenus(menus: MenuVO[] = []): number {
  return menus.reduce((total, item) => {
    const children = Array.isArray(item.children) ? item.children : []
    return total + 1 + countMenus(children)
  }, 0)
}

function getThemePrimaryColor(): string {
  if (typeof window === 'undefined') return '#409eff'
  return window.getComputedStyle(document.documentElement)
    .getPropertyValue('--omc-color-primary')
    .trim() || '#409eff'
}

function withAlpha(color: string, alpha: number): string {
  const value = color.replace('#', '')
  if (!/^[\da-f]{6}$/i.test(value)) return color
  const red = Number.parseInt(value.slice(0, 2), 16)
  const green = Number.parseInt(value.slice(2, 4), 16)
  const blue = Number.parseInt(value.slice(4, 6), 16)
  return `rgba(${red}, ${green}, ${blue}, ${alpha})`
}

export function useDashboardPage() {
  const userStore = useUserStore()

  const displayName = computed(() => (
    String(userStore.profile?.nickname || userStore.profile?.username || '未登录')
  ))
  const usernameText = computed(() => String(userStore.profile?.username || '-'))
  const menuCount = computed(() => countMenus(userStore.menus))
  const permissionCount = computed(() => userStore.permissions.length)

  const trendChartOptions = computed<EChartsCoreOption>(() => {
    const primaryColor = getThemePrimaryColor()
    return {
      tooltip: { trigger: 'axis' },
      grid: {
        left: 24,
        right: 24,
        top: 32,
        bottom: 24,
        containLabel: true
      },
      xAxis: {
        type: 'category',
        boundaryGap: false,
        data: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        axisLine: { lineStyle: { color: '#c8d1e0' } },
        axisLabel: { color: '#6b7280' }
      },
      yAxis: {
        type: 'value',
        axisLine: { show: false },
        splitLine: { lineStyle: { color: '#edf2f7' } },
        axisLabel: { color: '#6b7280' }
      },
      series: [
        {
          name: '数据走势图',
          type: 'line',
          smooth: true,
          symbol: 'circle',
          symbolSize: 8,
          data: [18, 26, 22, 31, 28, 36, 40],
          lineStyle: { width: 3, color: primaryColor },
          itemStyle: {
            color: primaryColor,
            borderColor: '#ffffff',
            borderWidth: 2
          },
          areaStyle: {
            color: {
              type: 'linear',
              x: 0,
              y: 0,
              x2: 0,
              y2: 1,
              colorStops: [
                { offset: 0, color: withAlpha(primaryColor, 0.28) },
                { offset: 1, color: withAlpha(primaryColor, 0.04) }
              ]
            }
          }
        }
      ]
    }
  })

  return {
    displayName,
    usernameText,
    menuCount,
    permissionCount,
    trendChartOptions
  }
}
