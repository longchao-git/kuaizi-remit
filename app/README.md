# uni-app 客户端模板

目录：`app`

依赖安装与静态检查使用 Node.js 24 LTS：`npm ci`、`npm exec -- tsc --noEmit`。类型检查不替代 HBuilderX 的目标平台编译；依赖升级还须执行经授权的 `npm audit --audit-level=low` 或 OSV 离线审计。

这是一个轻量的 uni-app + Vue 3 + TypeScript 起步模板，保留登录、首页、个人中心、资料编辑、统一请求与会话管理。H5/App 使用短信验证码登录，小程序保留微信登录并可切换短信；新微信身份需验证手机号并明确确认关联。不提供 mock。商品、订单、支付、钱包、客服等具体业务不放入模板。

## 保留能力

- 登录与登录态缓存
- 当前用户信息与退出登录
- 首页和“我的”两个 tabBar 页面
- 个人资料编辑分包页
- 统一 HTTP 请求、401 处理和登录后跳转
- 公共导航栏、胶囊底栏、空态、操作面板、图文卡、个人信息和菜单组件
- 接口地址与错误工具

## 目录

```text
app/
  handlers/
  navigation/
components/
  navbar/
config/
modules/
  auth/
  home/
  profile/
request/
shared/
  session/
  types/
  utils/
subPackages/
  login/
  profile/
static/
App.vue
main.ts
pages.json
manifest.json
uni.scss
```

## 运行

App 和小程序仍用 HBuilderX 打开 `app` 目录运行，交付前至少完成一次目标平台运行和发行构建。

### App 原生启动图

资产位于 `native-splash/`。Android 使用 480×762、720×1242、1080×1882 三档 `.9.png`，iOS 使用包含 `LaunchScreen.storyboard` 和两档 PNG 的 `LaunchScreen.zip`。`manifest.json` 已按项目内相对路径配置。Android 和 iOS 安装包从原生启动界面直接进入业务入口，H5 演示保留品牌启动页。

使用 HBuilderX 的「App 启动界面配置」核对路径并打包安装，再分别冷启动验证。普通运行基座的启动图由基座决定。参见 [DCloud 启动界面配置](https://uniapp.dcloud.net.cn/tutorial/app-splashscreen.html)。

H5 也可以在命令行运行（uni-app Vue3 CLI，Node 24+）：

```bash
npm install
npm run dev:h5    # http://localhost:5173
npm run build:h5  # 产物在 unpackage/dist/build/h5
```

项目沿用 HBuilderX 目录结构（`manifest.json`、`pages.json` 在根目录），`scripts/h5.mjs` 会在 CLI 启动前把 `UNI_INPUT_DIR` 指向根目录。

复制 `.env.example` 为 `.env.local` 并配置 `VITE_APP_BASE_URL`（H5 本地默认回退到 `http://127.0.0.1:8688/api/`，后端已开启 CORS）。真机和微信开发者工具不能通过 `127.0.0.1` 访问开发电脑，还需要配置可访问的 HTTPS 地址及微信 request/uploadFile 合法域名。

模板没有 mock。默认接口与 `api/spring-boot` 的 App 端点配套：短信/微信登录、微信手机关联、退出、当前会员、资料修改和文件上传，成功业务码为 `0`。需配置实际短信与微信服务，不能使用固定验证码；身份冲突不自动合并。更换后端时需同步调整 Repository 与 `request/http.ts` 的响应解包。

首次使用时需要替换：

- `manifest.json` 中的应用名、DCloud App ID、微信 App ID、H5 标题与实际权限
- 接口环境变量或 `config/api.ts` 中的本地兜底地址
- `pages.json` 中的页面标题和 tab 文案
- `static/` 中的 Logo、tab 图标和协议图标，以及 `uni.scss` 中的主题变量
- 登录页中的品牌、登录说明、用户协议和隐私条款入口
- `package.json` 的项目名与 `shared/session/storage.ts` 的存储 key
- 登录方式及对应服务端接口

真实 App ID、生产域名、Secret、用户数据和项目品牌不得提交回模板仓库。

## 开发约定

- 主包业务放在 `modules/`；非首屏页面放在 `subPackages/`，分包只引用主包能力。
- 页面文件命名为 `*Page.vue`，页面 hook 命名为 `use*Page.ts`。
- 页面不直接请求接口或读写本地存储。
- 新页面同步注册到 `pages.json`。
- 主题变量放在 `uni.scss`。
- 详细规则见 `docs/architecture/app.md`。

## 组件恢复说明

网校通用组件、原始图片和交互已按需恢复；首页“组件示例”可直接操作。复用入口、字号/内外边距与验证边界见 [组件基准](../docs/ui/visual-baseline.md)。

## 系统推送（Google FCM 与 Apple APNs）

1. 在 DCloud 开发者中心用实际 App ID 开通 UniPush 2.0，绑定与 HBuilderX 一致的 uniCloud 服务空间。本仓库提供阿里云服务空间目录的转发函数；项目使用其他服务商时将函数放入对应空间的 cloudfunctions 目录。
2. Android 只勾选 Google FCM 离线 SDK。准备实际 Android 包名、签名 SHA-1、匹配该包名的 google-services.json 和 Firebase Cloud Messaging HTTP v1 服务账号配置。iOS 使用 Apple APNs，准备 Bundle ID、Push Notifications 能力、推送密钥或证书，以及同一 Bundle ID 的打包签名和描述文件。
3. 关联服务空间后，由运维上传 remit-member-push 云函数、启用 HTTPS URL 访问，并在云函数环境变量设置 REMIT_PUSH_APP_ID 和 REMIT_PUSH_BRIDGE_SECRET。服务空间按 DCloud 文档准备 opendb-tempdata、opendb-device、uni-id-device。服务端和云函数使用相同的随机转发密钥，密钥仅放在各自的服务端环境。
4. Laravel 设置 REMIT_PUSH_ENABLED=true、REMIT_PUSH_BRIDGE_URL 和 REMIT_PUSH_BRIDGE_SECRET。执行数据库迁移，使用现有异步队列连接运行 php artisan queue:work --queue=member-push --timeout=15。App 设置 VITE_REMIT_PUSH_ENABLED=true，在 HBuilderX 为实际 App ID 勾选 UniPush 2.0 离线推送并构建自定义调试基座。当前源码的 App ID 尚未填入，推送默认关闭。
5. 使用支持 Google 移动服务的 Android 和已允许系统通知的 iPhone，分别验证前台、后台、关闭后通知、点开对应站内消息、换号及退出后的隔离。测试包需能访问测试 Laravel HTTPS API。当前联调令牌有效期为两小时，退出和过期会停止发送给该会话。

参考 [UniPush 2.0 开通](https://uniapp.dcloud.net.cn/uni-push/open.html)、[FCM 配置](https://uniapp.dcloud.net.cn/unipush_vendor_config.html#fcm) 和 [APNs 配置](https://docs.getui.com/getui/mobile/ios/apns/)。

## 筷子闪汇界面预览

在 HBuilderX 中打开 `app` 目录，运行到浏览器即可进入汇款预览。输入 9 位演示手机号并勾选协议，使用演示验证码 `805426` 登录。付款页可预览 SEPA、Apple Pay 和银行卡的费用，普通订单提交补件后可分别预览审核通过及退款。验证、支付、补件、客服和订单状态均使用本地演示数据。PHP 接入时通过 `VITE_APP_BASE_URL` 配置业务 API，正式报价、认证和订单状态由后端返回。
