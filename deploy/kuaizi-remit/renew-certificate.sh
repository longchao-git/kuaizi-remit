#!/usr/bin/env bash
set -euo pipefail
umask 077
ROOT=/opt/kuaizi-remit
exec 9>"$ROOT/certificate-renew.lock"
flock -n 9 || exit 0
IMAGE=$(cat "$ROOT/config/certbot-image")
CERT="$ROOT/config/letsencrypt/live/kuaizi-remit-test/fullchain.pem"
BEFORE=$(sha256sum "$CERT" | cut -d' ' -f1)
docker run --rm --cpus=0.5 --memory=512m \
    -v /www/wwwroot/kuaizi-remit-acme:/var/www/acme \
    -v "$ROOT/config/letsencrypt:/etc/letsencrypt" \
    "$IMAGE" renew --quiet --webroot -w /var/www/acme
AFTER=$(sha256sum "$CERT" | cut -d' ' -f1)
if [[ "$BEFORE" != "$AFTER" ]]; then
    /www/server/nginx/sbin/nginx -t
    /www/server/nginx/sbin/nginx -s reload
fi
