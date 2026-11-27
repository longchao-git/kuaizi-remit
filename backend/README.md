# 筷子闪汇 Laravel 后端

基于 [Laravel 官方应用模板 v13.11.0](https://github.com/laravel/laravel/releases/tag/v13.11.0)。项目代码采用 [PHP 后端框架规范](../docs/architecture/php-backend.md)。

## 本地运行

准备 PHP 8.3 或更高的受支持版本、Composer 2 和 `pdo_sqlite` 扩展。首次从项目根目录在 PowerShell 执行以下命令，创建本地 SQLite 数据库和应用密钥。

```powershell
Set-Location backend
composer install
if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    php artisan key:generate --no-interaction
    $phoneLookupKey = [Convert]::ToBase64String([Security.Cryptography.RandomNumberGenerator]::GetBytes(32))
    (Get-Content .env) -replace '^REMIT_PHONE_LOOKUP_KEY=$', "REMIT_PHONE_LOOKUP_KEY=$phoneLookupKey" | Set-Content .env
}
if (-not (Test-Path database/database.sqlite)) { New-Item database/database.sqlite -ItemType File | Out-Null }
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8688
```

已有 `.env` 时沿用已有应用密钥和数据库配置。只在首次创建 `.env` 后运行 `key:generate`。首次执行手机号加密迁移前，给既有 `.env` 补独立的 `REMIT_PHONE_LOOKUP_KEY` 随机值，格式为 32 字节的 Base64，之后由私有配置保管。迁移前检查 `DB_CONNECTION` 和 `DB_DATABASE` 指向预期数据库。

`GET /api/health` 可用于检查 API 进程及 `code/msg/data` 响应格式。当前健康检查不依赖数据库。`GET /up` 是 Laravel 自带的启动检查。

在 `backend/` 执行 `composer test` 和 `php vendor/bin/pint --test` 可运行测试和格式检查。测试固定使用内存 SQLite（`phpunit.xml` 覆盖 `$_ENV` 与 `$_SERVER`），不会连接本地 MySQL。

### 独立测试服务器

本项目的独立测试 API 为 `https://api.kzremit.com/`，管理后台为 `https://admin.kzremit.com/`。服务器运行配置、资源限制、持久化、证书和部署入口见 [独立测试环境部署](../deploy/kuaizi-remit/README.md)。下面的本地 Docker 编排不是该服务器的部署入口，不可混用数据库或凭据。

### Docker 本地运行

API 容器 `kuaizi-remit-api` 直接定义在 mt_sas 编排的 `docker-compose.yml` 中，与 `sas-mysql` 同属 `mt_sas_default` 网络，数据库主机名用 `mysql`。本地库为 `kuaizi_remit`，账号沿用 `root` / `root_pass`。镜像由本目录的 `Dockerfile` 构建，首次启动会自动生成 `.env`、APP_KEY、安装 Composer 依赖并执行迁移。

前提是数据库已创建：

```bash
docker exec sas-mysql mysql -uroot -proot_pass -e "CREATE DATABASE IF NOT EXISTS kuaizi_remit CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
```

在 mt_sas 目录启动 API（加上 override 与当前运行栈保持一致）：

```bash
cd /home/tbb/programs/mt_sas
docker compose -f docker-compose.yml -f /home/tbb/programs/mt_storage/deploy/docker-compose.sas-nginx.override.yml up -d kuaizi-remit-api
curl http://127.0.0.1:8688/api/health
docker logs -f kuaizi-remit-api
```

`.env` 只在缺失时生成，后续修改容器环境变量（如数据库密码）需要同步修改 `backend/.env`，`artisan serve` 的子进程只读取该文件。需要处理队列时，可临时在前台运行 `docker exec -it kuaizi-remit-api php artisan queue:work`。

根路径 `/` 是 Laravel 默认欢迎页，依赖 Vite 产物；只调试 API 时无需处理，需要访问欢迎页时先在 `backend/` 执行 `npm install && npm run build`（容器内不包含 Node）。

### 本地汇款联调账号

仅在 `APP_ENV=local` 时可用。先确认 SQLite 路径是本地开发库，在后端设置 `REMIT_LOCAL_LOGIN_EMAIL` 为专用测试邮箱，执行 `php artisan remit:create-local-account`，在终端提示时输入测试密码。命令不会覆盖已有账号。若环境变量通过 PowerShell 设置而非写入本地 `.env`，请用 `php artisan serve --host=127.0.0.1 --port=8688 --no-reload` 启动，保留传入的变量。

App 开发模式设置 `VITE_REMIT_LOCAL_LOGIN=true` 后，登录页会显示「使用测试账号」。试算需另设置 `REMIT_LOCAL_RATE_TEN_THOUSANDTHS`（如 `80540` 表示 `8.0540`），并通过后台保存费用和 ETA 规则。试算不锁汇、不生成订单、不能付款。环境或邮箱配置关闭后，已签发的联调令牌也失效。

App 也可用西班牙手机号和短信验证码登录。先执行数据库迁移，再在本地后端设置 app 环境为 local、`REMIT_LOCAL_SMS_LOGIN=true`、`REMIT_SMS_CHANNEL=log`。开启 `REMIT_SMS_TEST_AUTOFILL=true` 后，`/api/app/auth/sms-code` 会在响应里返回随机验证码，App 开发模式自动回填，无需真实短信。验证码 5 分钟有效、单次使用、最多试 5 次。测试登录默认关闭，只在 App 开发模式显示。同号首次登录会创建测试会员，之后复用该会员。线上 API 和线上会话不接受本地测试登录。生产短信渠道尚未接入，生产环境该流程返回 404。

### 平台员工角色

`REMIT_PLATFORM_ADMIN_EMAILS` 填已有员工邮箱，多个邮箱以逗号分隔。管理员登录后可通过「员工角色」分配运营、客服或只读角色，在「操作审计」查询已落地的后台操作。其他现有员工在首次分配角色前保留原有权限。保存新角色后员工须重新登录。

### 管理端账号

管理端用 `POST /api/user/login` 登录，账号为员工邮箱。初始化账号：

```bash
docker exec -it kuaizi-remit-api php artisan remit:create-admin admin@kuaizi.local --password='Admin@123456'
```

邮箱已存在时命令不会覆盖，需加 `--force` 重置密码并恢复员工标记（旧的后台令牌同时作废）。是否拥有管理员全量权限取决于该邮箱是否在 `REMIT_PLATFORM_ADMIN_EMAILS` 中；修改后重启容器生效。

## Reverb WebSocket

本地 `.env` 配置 `BROADCAST_CONNECTION=reverb` 和 `REVERB_APP_ID`、`REVERB_APP_KEY`、`REVERB_APP_SECRET`。另开一个终端，在 `backend/` 执行 `php artisan reverb:start`，HTTP API 继续用 `php artisan serve` 运行。`.env.example` 提供回环地址和端口，正式环境使用受信任域名、允许的 Origin 和 WSS。

客户端通过 `POST /api/broadcasting/auth` 申请私有频道签名，使用自己的 Bearer 令牌。`REVERB_APP_KEY` 用于连接，`REVERB_APP_SECRET` 留在服务端。客服和站内消息通过 HTTP 保存，通过 Reverb 通知客户端刷新或补收。

## 雅本汇款

`REMIT_CHANNEL=yaband` 使用雅本适配器。`YABAND_REMIT_ENVIRONMENT` 选 `sandbox` 或 `production`，配合各自的 URL、MID 和密钥。已有报价、订单、认证与补件在迁移时按原有环境、雅本地址和 MID 固定归属。迁移时保留原配置。切换后新报价使用新配置，涉及旧渠道的操作需连接原环境和商户；重试不串渠道。原身份和新环境独立注册，正式环境使用独立数据库。

`REMIT_CHANNEL=local` 仅支持 `APP_ENV=local` 的隔离 SQLite。配置 `REMIT_LOCAL_RATE_TEN_THOUSANDTHS`，用合成账号和正常费率规则在 App 中走模拟认证、下单及查单。`REMIT_LOCAL_SCENARIO` 控制下一次查单返回 `pending`、`paid`、`delivered`、`failed`、`refunding` 或 `refunded`；从配置更改场景后重新加载服务。模拟支付只生成本地订单编号，App 查单可看到模拟资金状态。按设计使用新订单测试失败分支，已确认的到账或退款状态受到回退保护。

雅本回调从可信代理提供的 `REMOTE_ADDR` 读取实际来源。`YABAND_REMIT_CALLBACK_SOURCE_IPS` 接受逗号分隔的 IP/CIDR，正式模式必填，沙箱可通过 `YABAND_REMIT_CALLBACK_IP_REQUIRED=true` 强制。交易回调验签后主动查询，只有业务查询和写入成功才缓存签名摘要 15 分钟。相同通知重发返回 `ok`，首次处理失败可重试；缓存外仍由资金状态检查和幂等保证一致性。通知格式、来源 IP 及有效重试需用上游实际报文联调。真实身份及资金启用前核对回源 HTTPS。

高风险会员 API 设备签名使用 P-256，公钥在登录校验通过后绑定本次 Sanctum Token。配置 `REMIT_REQUIRE_DEVICE_SIGNATURES=true` 后要求 `/app/kyc/idv`、`/app/kyc/profile`、`/app/remittance/orders` 的 POST 和收款资料草稿的 PUT 请求附签名，根路径和旧 `/api` 路径均受保护。签名包含请求方法、实际路径、正文 SHA-256、会话 Token SHA-256、时间和随机值。服务器拒绝过期、篡改和重复使用的签名。要求共享的数据库或 Redis 缓存，确保多实例也能原子占用随机值。

升级已有服务器应在维护窗口暂停旧版 API 和写入任务，备份后迁移，再启动新版。手机号明文字段在备份和密文迁移后移除，手机号核对改为完整 `+34` 号码。查询密钥与库绑定，配置异常会拒绝操作。更换查询密钥需受控重建索引，历史备份另按敏感数据管理。设备签名开关在新包验证后与 App 配置协调开启，旧会话需重新登录注册公钥。

`YABAND_REMIT_BASE_URL`、`YABAND_REMIT_MID`、`YABAND_REMIT_PASSWORD` 使用雅本开通的汇款 API 配置。认证入口另需 `YABAND_REMIT_KYC_REDIRECT_URL`、`YABAND_REMIT_KYC_NOTIFY_URL` 和 `YABAND_REMIT_EXTERNAL_UID_PREFIX`。同一项目环境使用固定前缀，开户重试复用本地已保存的请求编号。正式启用需要按汇款项目签名规范注册 `YabandRemitSigner` 实现。接口范围和沙箱待提供项见 [汇款对接说明](../docs/business/remit-integration.md#laravel-与雅本接口对应)。

本机沙箱可使用被 Git 忽略的 `.env.yaband-sandbox` 保存接入地址、MID、密码和 `YABAND_REMIT_SECRET_KEY`。在本地沙箱环境设置 `APP_KEY` 和 `CACHE_STORE=array`，从本目录运行 `php artisan remit:check-yaband --env=yaband-sandbox`，可验证 Token 并查看请求签名和回调验签组件的配置状态。若 Windows 便携 PHP 缺少 CA 文件，可用 `php -d curl.cainfo=<受信任的 CA 文件>` 执行同一命令。

### 雅本真实参考汇率

配置雅本地址、MID、密码和签名密钥后，设置 `YABAND_REMIT_REFERENCE_RATE_ENABLED=true`，App 现有报价接口即可使用 `/api/whitelable/transaction/queryExchangeRate` 返回的 EUR→CNY 参考汇率，再按后台费用与点差规则试算。该路径来自 MID700001 Postman 集合，不据其 `whitelable` 路径推定本商户支付模式。

响应小数先保留为十进制文本，再按现有四位汇率精度四舍五入为整数万分位；不使用浮点金额计算。币种、十进制格式或范围错误会拒绝报价。合法参考值按商户与上游地址隔离，缓存 60 秒，缓存命中保留原观察时间；过期查询失败不返回旧值，也不回退到本地配置汇率。

此类报价仍为 `mode=local_estimate`、`can_submit=false`，表示本地规则试算；新增 `rate_source=yaband_reference` 和 `reference_rate_observed_at` 标明真实来源及观察时间。可保存草稿，不生成汇款订单；源汇率和点差不直接返回会员。若已配置正式锁定报价适配器，保留其原有优先级。默认开关为 false，未启用时继续沿用现有本地联调逻辑。参考汇率查询通过不代表正式报价、本人收款或通知验签已经接通。

### 正式下单的查询与响应核对

正式报价及本人收款适配配置后，每次新支付或未确认支付的重试会先向雅本查询当前 KYC 和汇款资格，并复用原状态同步；旧的认证通过快照不能放行已过期、停用或查询失败的用户。已有交易编号的相同订单重试不重复创建支付，也不额外查询 KYC。报价有效期会在 KYC 查询后、收款信息准备后及实际请求支付前复查，到期返回 409，不请求付款。若订单已预留，保留原订单编号及未核销的券，不把本地拒绝伪装成上游取消或付款成功。

支付响应的订单号和交易号始终校验；携带 `uid`、`amount`、`currency` 时进一步核对付款会员、报价本金和 EUR，金额用两位小数字符串精确比较。字段不匹配时不保存交易号及付款链接，不核销券或发送到账消息；已经预留的支付请求仍保持待确认，禁止未经确认的自动重放。缺少这些可选响应字段时保留历史兼容校验，真实响应格式须在联调确认。

当前请求签名已有实现。正式报价、本人收款适配及回调验签尚未接通；文档的汇率查询页没有接口内容，通知签名章节明确首次和重发可能不同，不能套用请求 HMAC 宣称回调已接入。本地自动化测试使用上游模拟响应，不能代替真实资金验证。

### 漏通知后的订单查询补偿

升级先执行 `php artisan migrate`，增加 `provider_sync_attempted_at`。它记录最近的查单尝试，失败也记录；`provider_checked_at` 仍只代表已经成功核实的状态时间。已有数据不改写资金状态。

配置已完成查询联调的雅本环境后，可执行 `php artisan remit:sync-orders --limit=10`，只查询已有雅本交易编号的未结清订单，补齐付款、下发及退款状态。已下发、已退款、未付款终态订单和未取得交易编号的订单不在周期查询范围内；已保存交易编号的订单仍可通过后台单笔主动查单。它不创建支付、不执行下发或退款。失败时继续处理本批其他订单，命令返回非零并在日志记录订单引用和错误码，不输出上游正文或凭据。

自动查询默认关闭。部署迁移、确认签名和查单可用并配置 Laravel Scheduler 后，设置 `YABAND_REMIT_ORDER_SYNC_ENABLED=true`；每分钟调度一次，按订单退避间隔和批量上限执行，任务禁止重叠。默认同笔间隔 300 秒（允许 60–86400），每批 10 笔（允许 1–100），可分别用 `YABAND_REMIT_ORDER_SYNC_INTERVAL_SECONDS`、`YABAND_REMIT_ORDER_SYNC_BATCH_SIZE` 调整。配置错误会拒绝执行。失败订单也等待间隔，并按最久未尝试的顺序轮询；后台单笔查单和通过验签的通知不受这个周期限制，仍使用同一订单锁和归属校验。上线使用共享的持久化缓存锁，勿用 `array` 缓存运行多进程任务。

补偿任务不能代替真实通知验签及真实付款、到账、退款联调。雅本已说明 sandbox 付款会真实扣款，IDV 也使用真实身份；当前仅向指定人员私下交付测试版本，不公开上架苹果或谷歌商店。

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
