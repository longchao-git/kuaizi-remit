import { BASE_URL } from '@/config/api';
import type { ApiResponse } from '@/shared/types/api';
import { sessionService } from '@/shared/session/application/SessionService';
import { deviceSignatureHeaders } from './device-signature';

export interface HttpOptions {
  url: string;
  data?: unknown;
  method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
  header?: Record<string, string>;
  timeout?: number;
  handleUnauthorized?: boolean;
}

export class UnauthorizedError extends Error {
  constructor(message = '登录状态已过期') {
    super(message);
    this.name = 'UnauthorizedError';
  }
}

/** 保留字段位置供表单提示；不在日志或页面回显任意接口对象。 */
export class ValidationError extends Error {
  readonly fields: Record<string, string[]> = {};

  constructor(fields: unknown) {
    super('请核对填写内容');
    this.name = 'ValidationError';
    if (!fields || typeof fields !== 'object' || Array.isArray(fields)) return;
    for (const [key, messages] of Object.entries(fields).slice(0, 40)) {
      if (/^[a-zA-Z0-9_.]{1,80}$/.test(key) && Array.isArray(messages)) {
        const safe = messages.filter((item): item is string => typeof item === 'string' && item.length <= 200).slice(0, 3);
        if (safe.length) this.fields[key] = safe;
      }
    }
  }
}

let unauthorizedHandler: ((error: UnauthorizedError) => void | Promise<void>) | null = null;

export function setUnauthorizedHandler(handler: (error: UnauthorizedError) => void | Promise<void>): void {
  unauthorizedHandler = handler;
}

async function notifyUnauthorized(error: UnauthorizedError): Promise<void> {
  if (!unauthorizedHandler) {
    return;
  }

  await unauthorizedHandler(error);
}

export async function rejectUnauthorized(message = '登录状态已过期'): Promise<never> {
  const error = new UnauthorizedError(message);
  await notifyUnauthorized(error);
  throw error;
}

function resolveResponse<T>(response: { data: ApiResponse<T>; statusCode?: number }, handleUnauthorized: boolean): Promise<T> {
  const { data, statusCode = 200 } = response;
  const responseCode = data ? Number(data.code) : NaN;

  if (statusCode === 401 || responseCode === 401) {
    const message = (data && (data.message || data.msg)) || '登录状态已过期';
    return handleUnauthorized ? rejectUnauthorized(message) : Promise.reject(new UnauthorizedError(message));
  }

  if (statusCode === 422 && data?.data && typeof data.data === 'object' && !Array.isArray(data.data)
    && Object.values(data.data).some(Array.isArray)) {
    return Promise.reject(new ValidationError(data.data));
  }

  if (statusCode < 200 || statusCode >= 300) {
    return Promise.reject(new Error((data && (data.message || data.msg)) || `请求失败（${statusCode}）`));
  }

  if (responseCode === 0) {
    return Promise.resolve(((data && data.data !== undefined && data.data !== null ? data.data : data.body) as T));
  }

  return Promise.reject(new Error((data && (data.message || data.msg)) || '请求失败，请稍后重试'));
}

function http<T = unknown>(options: HttpOptions): Promise<T> {
  const { url, data, method = 'GET', header = {}, timeout = 60000, handleUnauthorized = true } = options;
  const token = sessionService.getToken();
  const address = BASE_URL + url;
  const requestData = method === 'POST' || method === 'PUT' ? JSON.stringify(data) : data;

  return new Promise((resolve, reject) => {
    const proof = deviceSignatureHeaders(address, method, typeof requestData === 'string' ? requestData : '', token);
    uni.request({
      url: address,
      method,
      data: requestData as string | AnyObject | ArrayBuffer | undefined,
      header: {
        'content-type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...header,
        ...proof,
      },
      timeout,
      success: (res) => {
        resolveResponse<T>(res as unknown as { data: ApiResponse<T>; statusCode?: number }, handleUnauthorized)
          .then(resolve)
          .catch(reject);
      },
      fail: () => {
        reject(new Error('网络连接失败，请检查网络'));
      }
    });
  });
}

const request = {
  get<T = unknown>(url: string, data: unknown = {}, options: Omit<HttpOptions, 'url' | 'data' | 'method'> = {}): Promise<T> {
    return http<T>({ url, data, method: 'GET', ...options });
  },

  post<T = unknown>(url: string, data: unknown = {}, options: Omit<HttpOptions, 'url' | 'data' | 'method'> = {}): Promise<T> {
    return http<T>({ url, data, method: 'POST', ...options });
  },

  put<T = unknown>(url: string, data: unknown = {}, options: Omit<HttpOptions, 'url' | 'data' | 'method'> = {}): Promise<T> {
    return http<T>({ url, data, method: 'PUT', ...options });
  },

  delete<T = unknown>(url: string, options: Omit<HttpOptions, 'url' | 'data' | 'method'> = {}): Promise<T> {
    return http<T>({ url, method: 'DELETE', ...options });
  }
};

export default request;
