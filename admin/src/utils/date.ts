/**
 * 统一日期入参类型
 */
export type DateInput = Date | string | number | undefined | null

const pad = (value: number): string => String(value).padStart(2, '0')

/**
 * 更宽松的日期解析，兼容 Date / 时间戳 / YYYY-MM-DD HH:mm:ss / YYYY/MM/DD HH:mm:ss
 */
export const parseFlexibleDate = (input: DateInput): Date | null => {
  if (input === undefined || input === null || input === '') return null

  if (input instanceof Date) {
    const d = new Date(input.getTime())
    return isNaN(d.getTime()) ? null : d
  }

  if (typeof input === 'number') {
    const d = new Date(input)
    return isNaN(d.getTime()) ? null : d
  }

  if (typeof input === 'string') {
    let d = new Date(input)
    if (!isNaN(d.getTime())) return d

    const normalized = input.trim().replace(/\//g, '-')
    const match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?$/)
    if (match) {
      const [, year, month, day, hour = '0', minute = '0', second = '0'] = match
      d = new Date(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second))
      return isNaN(d.getTime()) ? null : d
    }

    d = new Date(input.replace(' ', 'T'))
    if (!isNaN(d.getTime())) return d
  }

  return null
}

/**
 * 通用日期格式化
 * 默认用于后台时间显示：YYYY-MM-DD HH:mm
 */
export const formatDate = (date: DateInput, format = 'YYYY-MM-DD HH:mm'): string => {
  const d = parseFlexibleDate(date)
  if (!d) return ''

  const replacements: Record<string, string> = {
    YYYY: String(d.getFullYear()),
    MM: pad(d.getMonth() + 1),
    DD: pad(d.getDate()),
    HH: pad(d.getHours()),
    mm: pad(d.getMinutes()),
    ss: pad(d.getSeconds())
  }

  return format.replace(/YYYY|MM|DD|HH|mm|ss/g, token => replacements[token])
}

/**
 * 后台默认时间格式：YYYY-MM-DD HH:mm
 */
export const formatAdminDateTime = (date: DateInput): string => formatDate(date, 'YYYY-MM-DD HH:mm')
