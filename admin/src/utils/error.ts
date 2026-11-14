/**
 * 提取统一的错误信息
 * @param e 任意错误对象
 * @param fallback 兜底提示
 */
export const getErrorMessage = (e: unknown, fallback: string): string => {
  if (typeof e === 'string' && e.trim().length > 0) return e
  if (e && typeof e === 'object') {
    const maybe = e as { message?: unknown }
    const msg = maybe.message
    if (typeof msg === 'string' && msg.trim().length > 0) return msg
  }
  return fallback
}
