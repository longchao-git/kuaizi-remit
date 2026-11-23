/**
 * 接口配置
 */

const DEFAULT_BASE_URL = 'https://api.kzremit.com/';
const rawBaseUrl = import.meta.env.VITE_APP_BASE_URL || DEFAULT_BASE_URL;

export const BASE_URL = rawBaseUrl.endsWith('/') ? rawBaseUrl : `${rawBaseUrl}/`;
