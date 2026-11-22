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
}
if (-not (Test-Path database/database.sqlite)) { New-Item database/database.sqlite -ItemType File | Out-Null }
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8688
```

已有 `.env` 时沿用已有应用密钥和数据库配置。只在首次创建 `.env` 后运行 `key:generate`，迁移前检查 `DB_CONNECTION` 和 `DB_DATABASE` 指向本地开发库。

`GET /api/health` 可用于检查 API 进程及 `code/msg/data` 响应格式。当前健康检查不依赖数据库。`GET /up` 是 Laravel 自带的启动检查。

在 `backend/` 执行 `composer test` 和 `php vendor/bin/pint --test` 可运行测试和格式检查。测试固定使用内存 SQLite（`phpunit.xml` 覆盖 `$_ENV` 与 `$_SERVER`），不会连接本地 MySQL。

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

`YABAND_REMIT_BASE_URL`、`YABAND_REMIT_MID`、`YABAND_REMIT_PASSWORD` 使用雅本开通的汇款 API 配置。认证入口另需 `YABAND_REMIT_KYC_REDIRECT_URL`、`YABAND_REMIT_KYC_NOTIFY_URL` 和 `YABAND_REMIT_EXTERNAL_UID_PREFIX`。同一项目环境使用固定前缀，开户重试复用本地已保存的请求编号。正式启用需要按汇款项目签名规范注册 `YabandRemitSigner` 实现。接口范围和沙箱待提供项见 [汇款对接说明](../docs/business/remit-integration.md#laravel-与雅本接口对应)。

本机沙箱可使用被 Git 忽略的 `.env.yaband-sandbox` 保存接入地址、MID、密码和 `YABAND_REMIT_SECRET_KEY`。在本地沙箱环境设置 `APP_KEY` 和 `CACHE_STORE=array`，从本目录运行 `php artisan remit:check-yaband --env=yaband-sandbox`，可验证 Token 并查看请求签名和回调验签组件的配置状态。若 Windows 便携 PHP 缺少 CA 文件，可用 `php -d curl.cainfo=<受信任的 CA 文件>` 执行同一命令。

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
