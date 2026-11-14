/**
 * API响应接口
 */
export interface ApiResponse<T> {
  code?: number;
  data?: T;
  msg?: string;
  message?: string;
}
