/**
 * 接口配置
 */

const DEFAULT_BASE_URL = 'http://127.0.0.1:8688/api/';
const rawBaseUrl = import.meta.env.VITE_APP_BASE_URL || DEFAULT_BASE_URL;

export const BASE_URL = rawBaseUrl.endsWith('/') ? rawBaseUrl : `${rawBaseUrl}/`;
