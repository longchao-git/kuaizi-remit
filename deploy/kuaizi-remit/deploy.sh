#!/usr/bin/env bash
set -euo pipefail
umask 077

ROOT=/opt/kuaizi-remit
RELEASE_ID=${1:?Pass the immutable source commit}
[[ "$RELEASE_ID" =~ ^[0-9a-f]{7,40}$ ]] || { echo 'Invalid release identifier'; exit 2; }
RELEASE="$ROOT/releases/$RELEASE_ID"
[[ -f "$RELEASE/backend/artisan" && -d "$RELEASE/admin-dist/assets" ]] || { echo 'Release files are incomplete'; exit 2; }
exec 9>"$ROOT/deploy.lock"
flock -n 9 || { echo 'A deployment is already running'; exit 1; }
test -f "$ROOT/config/app.env"
test -f "$ROOT/config/database.env"
test -f "$ROOT/config/compose.yaml"
df -Pk "$ROOT" | awk 'NR == 2 { if ($4 < 8*1024*1024) exit 1 }' || { echo 'At least 8 GiB free disk is required'; exit 1; }
install -d -m 755 -o 82 -g 82 "$ROOT/data/storage/app/public" "$ROOT/data/storage/framework/cache/data" \
    "$ROOT/data/storage/framework/sessions" "$ROOT/data/storage/framework/views" "$ROOT/data/storage/logs"
chmod 755 "$ROOT/data/storage" "$ROOT/data/storage/app" "$ROOT/data/storage/framework" "$ROOT/data/storage/framework/cache"
chown 82:82 "$ROOT/data/storage" "$ROOT/data/storage/app" "$ROOT/data/storage/framework" "$ROOT/data/storage/framework/cache"

export RELEASE_ID
export DOCKER_BUILDKIT=0
docker build --memory=2g --cpu-shares=512 -t "kuaizi-remit-php:$RELEASE_ID" -f "$RELEASE/deploy/kuaizi-remit/php.Dockerfile" "$RELEASE/backend"
docker build --memory=512m --cpu-shares=256 -t "kuaizi-remit-web:$RELEASE_ID" -f "$RELEASE/deploy/kuaizi-remit/web.Dockerfile" "$RELEASE/backend"
dc() { docker compose -p kuaizi-remit -f "$ROOT/config/compose.yaml" "$@"; }
dc config --quiet
dc up -d --wait --wait-timeout 180 database
APPLICATION_SUBNET=$(docker network inspect kuaizi-remit-application --format '{{(index .IPAM.Config 0).Subnet}}')
WEB_TEMPLATE="$ROOT/config/web.template.conf"
[[ -f "$WEB_TEMPLATE" ]] || WEB_TEMPLATE="$RELEASE/deploy/kuaizi-remit/web.conf"
python3 - "$WEB_TEMPLATE" "$ROOT/config/web.conf" "$APPLICATION_SUBNET" <<'PY'
import ipaddress
import sys
from pathlib import Path

network = ipaddress.ip_network(sys.argv[3])
if not network.is_private:
    raise RuntimeError('Only the private application bridge may be trusted')
template = Path(sys.argv[1]).read_text()
if '# KUAIZI_REALIP_TRUST' not in template:
    raise RuntimeError('Missing real-IP trust insertion marker')
trust = f'set_real_ip_from {network};\nset_real_ip_from 127.0.0.1;\nreal_ip_header X-Forwarded-For;\nreal_ip_recursive on;'
Path(sys.argv[2]).write_text(template.replace('# KUAIZI_REALIP_TRUST', trust))
PY

# 升级数据库前只备份本项目；不输出密码，不覆盖旧备份。
if [[ -f "$ROOT/current-release" ]]; then
    install -d -m 700 "$ROOT/data/backups"
    dc exec -T database sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump --no-tablespaces --single-transaction -u "$MYSQL_USER" "$MYSQL_DATABASE"' \
        | gzip > "$ROOT/data/backups/database-$(date -u +%Y%m%dT%H%M%SZ).sql.gz"
fi
dc run --rm --no-deps api php artisan migrate --force --no-interaction
dc up -d --wait --wait-timeout 180 api reverb queue scheduler web
dc exec -T web nginx -t
dc exec -T web nginx -s reload
curl --fail --silent --show-error --max-time 15 http://127.0.0.1:8688/health > "$ROOT/logs/health-last.json"
grep -q '"status":"ok"' "$ROOT/logs/health-last.json"
printf '%s\n' "$RELEASE_ID" > "$ROOT/current-release"
docker inspect --format '{{.Image}}' "$(dc ps -q api)" > "$ROOT/logs/api-image-digest"
echo "Deployment healthy: $RELEASE_ID"
