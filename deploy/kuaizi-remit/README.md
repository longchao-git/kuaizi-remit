# 筷子闪汇独立测试环境部署

仅在用户明确授权的服务器执行。现有官网目录和站点配置不参与本部署；API 与后台使用独立域名及独立数据。源码版本、部署包 SHA256、构建镜像 ID 和实际验证结果保存在本轮交付记录中。

| 入口 | 地址 | 服务 |
| --- | --- | --- |
| 官网 | https://www.kzremit.com/ | 既有网站，保留现状 |
| API | https://api.kzremit.com/ | Laravel 根路径，经源站 Nginx → 127.0.0.1:8688；旧 /api 路径兼容 |
| 后台 | https://admin.kzremit.com/ | Vue 静态资源，经源站 Nginx → 127.0.0.1:8689 |
| 实时连接 | wss://api.kzremit.com/app/ | Reverb，仅代理客户端连接 |

## 布局与前置条件

`/opt/kuaizi-remit/releases/<固定提交>/` 包含 backend、admin-dist 及本目录。`config/` 保存私有环境、编排、网关及证书，`data/` 保存数据库、Laravel storage 和备份，`logs/` 保存部署及检查记录。源站仅新建 api/admin 两个 vhost，不改变官网配置。

安装 Docker 与经过 SHA256 验证的固定 Compose 插件；不要全系统升级、全局 prune 或重置已有账号。服务器需至少 8 GiB 可用空间；初始配置限制构建内存 2 GiB，MySQL 2 GiB、PHP 768 MiB，Reverb/队列/调度各 256 MiB，网关 192 MiB。数据库及 FPM 不发布公网端口，Web 只绑定回环地址。

## 配置与执行

将 app.env.example 的实际值写入服务器 `config/app.env`，另建 `config/database.env`（MYSQL_DATABASE、MYSQL_USER、MYSQL_PASSWORD、MYSQL_ROOT_PASSWORD）。密钥和密码只在私有环境文件里，权限 600，不放源码、镜像、构建参数或 Git。APP_KEY 首次生成后保留；后续部署不重新生成密码或管理员。

后台设置 `VITE_API_BASE=https://api.kzremit.com/`，直接跨域调用 API 根地址；App 使用同一根地址。构建时设置实际 WSS 地址及公开的 Reverb App Key，产物放当前版本的 admin-dist。Laravel 保留旧 `/api` 别名并共享权限/校验/限流，CORS 允许实际后台来源。部署前校验包摘要、Shell 语法、Compose 配置和本项目端口；已有其他站点或冲突端口时停止，不改用任意端口或覆盖它们。

内部 API 网关把普通 `/app/...` 请求交给 Laravel，仅在 `Upgrade: websocket` 时转到 Reverb，避免规范会员路径与实时连接冲突。`/` 和 `/health` 返回健康 JSON；`/release.json` 和 `/storage/` 保留各自入口。切换前在本项目网络内运行 Nginx 配置检查，部署后同时验证 REST、WebSocket、跨域预检和旧路径。

复制 compose.yaml 到 config，将 web.conf 作为 config/web.template.conf 保存，将 deploy.sh 安装为 `/opt/kuaizi-remit/deploy.sh`，再执行：

```bash
bash /opt/kuaizi-remit/deploy.sh <固定提交>
```

脚本使用部署锁，先构建新镜像，数据库健康后执行兼容迁移，再启动服务。后续更新会先备份本项目数据库，保留历史快照；首轮无历史数据时不冒充已有备份。镜像回退不代表数据库回退，迁移不兼容时需人工核对恢复方案。

首次管理员使用现有 `remit:create-admin`，密码从私有输入传入，不使用命令参数日志或 `--force` 覆盖账号。服务器规则初始为空，必须由有权限的运营保存确认的费率和到账规则；不把自动化测试中的假设金额当成正式商务口径。

## TLS 与 Cloudflare

新域名先通过独立 HTTP-01 目录取得受信任证书，再启用源站 TLS。优先采用 Full (strict)，不全局切换官网 SSL 策略。源站直连的 TLS 与 Cloudflare 的 SSL 模式须分开验证。

2026-11-23 用户补充客户已配置二级域名，并明确其现有方案是 Cloudflare HTTPS → 源站 HTTP 80。测试环境据此使用 enable-cloudflare-http.sh：仅 api/admin 的 80 端口接受 Cloudflare 公布网段且边缘请求为 HTTPS 的代理流量，其余直连返回 403、边缘 HTTP 跳转 HTTPS；HTTP-01 挑战目录独立放行。官网不引用这些变量，也不修改配置。443 的有效证书和代理入口保留，可在后续改成 Full (strict) 时继续使用。

此方案的 Cloudflare 到源站这一段仍是 HTTP，不能描述成全链路 TLS。当前仅用于测试管理员及非付款试算，KYC、正式付款等门槛仍关闭；接入真实身份或资金前应使用源站 HTTPS。Cloudflare 网段来自官方 ips-v4 / ips-v6 清单，变更时仅更新本项目的 cloudflare-edge.conf 并验证 Nginx 配置。

renew-certificate.sh 使用首次签发时锁定的 Certbot 镜像摘要，只在证书变化时校验并平滑重载 Nginx。本机未安装 crontab，使用本项目专用 systemd service/timer，每日 UTC 03:17 加随机延迟检查，保留其他任务；续期日志由 systemd journal 管理，容器日志使用 Compose 的 local 驱动轮转。不要清理其他项目镜像、容器或文件。

网关只信任本次 Docker application 网络与回环地址的真实 IP 转发；源站将 Cloudflare 请求还原为 CF-Connecting-IP，直连 TLS 请求仍使用实际连接地址，再向内部网关传递。不要信任公网客户端自行填写的 X-Forwarded-For，避免所有请求共用 Docker 网桥地址或伪造限流身份。

## 验证与边界

从真实公开地址验证 HTTPS、release.json、API 健康、数据库连接、后台登录/菜单/权限、子路由刷新、静态资源、API 拒绝边界及实时连接。源站直连验证需单独记录，不代替经过 Cloudflare 的公开访问。

当前连接雅本 sandbox，可查询真实参考汇率；正式报价、本人收款和通知验签仍受原门槛保护，不执行真实身份、扣款、到账或退款。正式短信未接入，本地测试登录保持关闭。服务器部署不代表 App 已打包或真机通过，也不代表整个资金业务验收完成。

## 受控测试报告

固定入口为 `https://admin.kzremit.com/kuaizi-remit/acceptance/`，历史记录在其交付/执行编号目录内。仅 admin 新增受限静态路由；独立基本认证凭据通过私有交付文件保管，匿名访问 HTML、Markdown 和证据均应返回 401，不公开原始执行 JSON、服务器或第三方配置。

私有报告及上传包保存在 `/opt/kuaizi-remit/reports/`。Nginx 展示副本只含获准的 HTML、Markdown 和脱敏证据，放在独立 `/www/wwwroot/kuaizi-remit-reports/`，避免放宽项目根目录 0700；该目录不属于既有官网。发布时先验证新历史地址，再切换固定入口，保留旧执行。报告路由使用 private/no-store/no-transform，防止 CDN 邮箱改写影响测试账号显示；仅该路由生效。不能用折叠账号区域代替访问控制，也不修改全站 Cloudflare 策略。
