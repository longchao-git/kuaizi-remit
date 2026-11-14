import axios, { type AxiosError, type AxiosRequestConfig, type AxiosResponse } from 'axios'
import { API_CONFIG } from '@/config/api'
import { TOKEN_STORAGE_KEY } from '@/config/storage'
import type { ApiResponse } from '@/shared/types/api/ApiResponse'

export const http = axios.create({
  baseURL: API_CONFIG.BASE_URL,
  timeout: 50_000
})

export class HttpError extends Error {
  readonly status?: number
  readonly code?: number

  constructor(message = '请求失败', status?: number, code?: number) {
    super(message)
    this.name = 'HttpError'
    this.status = status
    this.code = code
  }
}

export class UnauthorizedError extends HttpError {
  constructor(message = '登录已过期') {
    super(message, 401, 401)
    this.name = 'UnauthorizedError'
  }
}

export class NetworkError extends Error {
  constructor(message = '网络连接失败，请稍后重试') {
    super(message)
    this.name = 'NetworkError'
  }
}

export function isUnauthorizedError(error: unknown): error is UnauthorizedError {
  return error instanceof UnauthorizedError || (
    error instanceof Error && error.name === 'UnauthorizedError'
  )
}

export function isNetworkError(error: unknown): error is NetworkError {
  return error instanceof NetworkError || (
    error instanceof Error && error.name === 'NetworkError'
  )
}

let unauthorizedHandler: ((error: UnauthorizedError) => void | Promise<void>) | null = null

export function setUnauthorizedHandler(handler: (error: UnauthorizedError) => void | Promise<void>): void {
  unauthorizedHandler = handler
}

function rejectUnauthorized(message?: string): Promise<never> {
  const error = new UnauthorizedError(message || '登录已过期')
  if (unauthorizedHandler) {
    void Promise.resolve(unauthorizedHandler(error)).catch(() => undefined)
  }
  return Promise.reject(error)
}

function normalizeCode(value: unknown): number | undefined {
  const code = Number(value)
  return Number.isFinite(code) ? code : undefined
}

http.interceptors.request.use(
  config => {
    config.headers = config.headers || {}
    const token = localStorage.getItem(TOKEN_STORAGE_KEY)
    if (token) config.headers.Authorization = `Bearer ${token}`
    config.headers['Accept-Language'] = localStorage.getItem('locale') || 'zh-CN'
    return config
  },
  error => Promise.reject(error)
)

http.interceptors.response.use(
  (response: AxiosResponse<unknown>): AxiosResponse | Promise<AxiosResponse> => {
    const data = response.data
    if (!data || typeof data !== 'object' || !('code' in data)) {
      return data as AxiosResponse
    }

    const result = data as ApiResponse<unknown>
    const code = normalizeCode(result.code)
    const message = result.msg || result.message
    if (code === 401) return rejectUnauthorized(message)
    if (code === 0) return result.data as AxiosResponse
    return Promise.reject(new HttpError(message || '请求失败', response.status, code))
  },
  (error: AxiosError<ApiResponse<unknown>>) => {
    const responseData = error.response?.data
    const responseMessage = responseData?.msg || responseData?.message
    if (error.response?.status === 401) {
      return rejectUnauthorized(responseMessage)
    }
    if (error.response) {
      const message = responseMessage || `请求失败 (${error.response.status})`
      return Promise.reject(new HttpError(
        message,
        error.response.status,
        normalizeCode(error.response.data?.code)
      ))
    }

    const timedOut = error.code === 'ECONNABORTED' || error.code === 'ETIMEDOUT'
    return Promise.reject(new NetworkError(
      timedOut ? '请求超时，请稍后重试' : '网络连接失败，请检查网络后重试'
    ))
  }
)

export function get<T>(url: string, params?: Record<string, unknown>, config?: AxiosRequestConfig): Promise<T> {
  return http.get<ApiResponse<T>, T>(url, { params, ...config }) as unknown as Promise<T>
}

export function post<T, D = unknown>(url: string, data?: D, config?: AxiosRequestConfig<D>): Promise<T> {
  return http.post<ApiResponse<T>, T, D>(url, data, config) as unknown as Promise<T>
}

export function put<T, D = unknown>(url: string, data?: D, config?: AxiosRequestConfig<D>): Promise<T> {
  return http.put<ApiResponse<T>, T, D>(url, data, config) as unknown as Promise<T>
}

export function del<T>(url: string, config?: AxiosRequestConfig): Promise<T> {
  return http.delete<ApiResponse<T>, T>(url, config) as unknown as Promise<T>
}
