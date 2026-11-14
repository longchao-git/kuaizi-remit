/**
 * 基础查询参数接口
 */
export interface BaseQueryParams {
  current?: number;
  size?: number;
  [key: string]: unknown;
}
