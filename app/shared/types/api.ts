export interface ApiResponse<T = unknown> {
  code: number | string;
  msg?: string;
  message?: string;
  body?: T;
  data?: T;
}
