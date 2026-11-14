// 按构建入口隔离同源部署的项目与后台主题，不迁移其他入口的会话。
export const TOKEN_STORAGE_KEY = `${import.meta.env.BASE_URL}:token`
export const SESSION_STORAGE_KEY = `${import.meta.env.BASE_URL}:session`
