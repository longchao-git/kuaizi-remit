// API配置
export const API_CONFIG = {
    // 统一 API 前缀。开发环境走 Vite 代理，生产环境走 Nginx 反向代理。
    BASE_URL: import.meta.env.VITE_API_BASE || '/api',
}
