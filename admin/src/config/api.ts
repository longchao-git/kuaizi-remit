// API配置
export const API_CONFIG = {
    // 后台与 App 共用独立 API 根域名；本地调试仍可显式覆盖。
    BASE_URL: import.meta.env.VITE_API_BASE || 'https://api.kzremit.com/',
}
