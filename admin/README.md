# Standard Admin Template

通用后台管理模板，适合作为传统运营后台、企业管理系统和内部工具的起点。复制整个目录即可使用。

## 快速开始

构建使用 Node.js 24 LTS 和 npm。依赖升级后同时运行 `npm run check` 与经授权的 `npm run audit`；受限环境可使用仓库安全规范中的 OSV 离线检查。

在项目根目录运行：

```powershell
cd .\admin
Copy-Item .env.example .env.local
npm ci
npm run check
npm run dev
```

`VITE_API_BASE` 是浏览器请求前缀，开发代理会使用同一前缀；`VITE_PROXY_TARGET` 只配置本地后端地址。

## 已内置的能力

- 登录、退出、当前用户、菜单和权限的会话初始化。
- 静态布局路由 + 后端菜单动态路由。
- 用户、角色、菜单、字典、首页和“我的账户”模块。
- `BaseTable`、`SearchForm`、`PageFooter`、`FormDialog`、`ListPageLayout`。
- 文件上传、TinyMCE 富文本和 ECharts 基础封装。

业务页面放在 `src/modules/<module>`，按需使用 `domain/application/repository/pages`。后端菜单的 `component` 与页面映射集中维护在 `src/router/componentMap.ts`。

## 后端与权限约定

- 接口返回统一使用 `{ code, message, data }`，`code = 0` 表示成功。
- Token 通过 `Authorization: Bearer <token>` 发送。
- 只有 401 清理会话并回到登录页；403 和其他 HTTP/业务错误保留登录态并显示原始错误。
- 仅“网络错误且本地存在有效用户会话缓存”时允许离线降级；无缓存或服务端错误会中止导航。
- 页面按钮使用 `v-permission`；`BaseTable` 行操作使用 `permission`，静态与动态路由由路由守卫检查权限。
- 用户资料编辑不携带角色和密码。角色只走“分配角色”专用操作；新增用户仅在拥有 `system:user:assignRole` 时展示、校验并提交 `roleIds`；可分配角色选项统一通过 `GET /role/options` 获取。
- 角色基础新增/编辑只有在拥有 `system:role:assignMenu` 时才携带 `menuIds`；独立“分配权限”使用角色菜单专用接口，克隆同时要求新增和菜单分配权限。

## 上传组件契约

`src/components/upload/BaseUpload.vue` 支持两种扩展方式：

- `uploadAdapter(file, { onProgress }) => Promise<string>`
- `httpRequest(options) => Promise<string>`

两者都必须以文件 URL 作为 Promise 结果。自定义 `httpRequest` 应通过 `options.onProgress` 上报 Element Plus 进度事件；组件会统一维护 `uploading`、进度、单文件/多文件值以及 `success`、`error`、`uploading-change` 事件。默认适配器位于 `src/request/upload.ts`。

## TinyMCE 契约

富文本组件使用 TinyMCE 8 的 Promise 上传接口，本地语言包和皮肤位于 `public/tinymce`，不依赖 CDN。

使用 `TinymceEditor` 前必须在 `.env.local` 配置 `VITE_TINYMCE_LICENSE_KEY`：

- 仅当项目满足 GPL 许可要求时填写 `gpl`。
- 商业许可项目填写 TinyMCE 提供的 `T8LK:...`。

模板不预设许可值，也不要提交真实商业密钥。

## 复制后的最小修改清单

1. 修改 `package.json` 和 `package-lock.json` 的项目名，以及 `index.html` 标题。
2. 配置 `.env.local` 中的 API 前缀、代理地址和 TinyMCE 许可；同域部署多个后台时给 `token`、`session` 和列表状态存储键增加项目命名空间。
3. 替换登录页文案、Logo、背景图和主题变量；子路径部署时同步修改 Vite `base`。
4. 按后端 `sys_menu.component` 更新 `src/router/componentMap.ts`，并保持权限码一致。
5. 删除项目不使用的示例模块、组件和素材，同时删除对应路由映射与菜单数据。
6. 执行 `npm run check`，再手工验证登录、刷新恢复、无权限访问、角色权限分配、文件上传和生产构建。

`npm run check` 会依次执行 ESLint、TypeScript 类型检查和生产构建。模板中不得保留真实域名、生产密钥或来源项目品牌。

## 筷子闪汇后台界面预览

运行开发服务器后访问 `/#/remit-preview`，可查看汇款订单、汇款用户、本人到账账户、只读 KYC 状态、补件、客服工单和状态原因码。客服工单支持仅看未读、预览回复和查看关联订单。通过「付款与到账规则」可编辑演示费率和提示，按人民币收款渠道预览不同金额档的 ETA。规则草稿、已读状态和回复保留在当前预览会话，正式数据待接 PHP 接口。

运营预览包含经营仪表盘、账户风控、定价点差与补贴预算、报价记录、毛利预估、优惠券模板与发券、提醒计划、对账差异、运营配置及审计。CSV 预览使用 `order_id,amount_eur` 两列表头。登录和角色授权复用通用后台入口，业务规则及运营数据仍是本地演示。
