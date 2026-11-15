#!/bin/sh
# 本地开发启动脚本：补齐 .env / Composer 依赖 / APP_KEY，等待 MySQL 后执行迁移。
# 通过环境变量可关闭其中任一步骤：DB_WAIT=false、RUN_MIGRATIONS=false、INSTALL_DEPS=false。
set -eu

cd /var/www/html

log() { printf '[kuaizi-remit] %s\n' "$*"; }

# 1. .env 只在缺失时创建，已存在的本地配置不会被覆盖。
#    DB_* 会写进 .env，因为 artisan serve 的子进程只读取 .env。
if [ ! -f .env ]; then
    cp .env.example .env
    php docker/apply-env.php .env
    log "已生成 .env，数据库指向 ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}/${DB_DATABASE:-laravel}"
fi

# 2. 已存在的 .env 优先（artisan serve 子进程只读取 .env），不一致时给出提醒。
if [ "${DB_CONNECTION:-}" != "" ] && ! grep -qE "^DB_CONNECTION=${DB_CONNECTION}$" .env; then
    log "提醒：.env 中的 DB_CONNECTION 与容器环境不一致，请同步 backend/.env"
fi

# 3. 依赖只安装一次，vendor 目录落在宿主 backend/vendor（已被 .gitignore 忽略）。
if [ "${INSTALL_DEPS:-true}" = "true" ] && [ ! -f vendor/autoload.php ]; then
    log "安装 Composer 依赖…"
    composer install --no-interaction --prefer-dist --no-progress
fi

# 4. 缺少 APP_KEY 时生成，保证加密、会话与 Sanctum 令牌可用。
if ! grep -qE '^APP_KEY=base64:.+' .env; then
    log "生成 APP_KEY…"
    php artisan key:generate --force --no-interaction
fi

# 5. 等待外部 MySQL（sas-mysql）就绪，最多约 2 分钟。
if [ "${DB_WAIT:-true}" = "true" ] && [ "${DB_CONNECTION:-sqlite}" = "mysql" ]; then
    log "等待 MySQL ${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}…"
    attempt=0
    until php -r '
        try {
            new PDO(
                sprintf("mysql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT") ?: 3306, getenv("DB_DATABASE")),
                getenv("DB_USERNAME"),
                getenv("DB_PASSWORD"),
                [PDO::ATTR_TIMEOUT => 3],
            );
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage().PHP_EOL);
            exit(1);
        }
    ' 2>/dev/null; do
        attempt=$((attempt + 1))
        if [ "$attempt" -ge 60 ]; then
            log "等待 MySQL 超时，请确认 sas-mysql 容器已启动"
            exit 1
        fi
        sleep 2
    done
fi

# 6. 迁移是幂等的，每次启动执行可保证表结构最新。
if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    log "执行数据库迁移…"
    php artisan migrate --force --no-interaction
fi

exec "$@"
