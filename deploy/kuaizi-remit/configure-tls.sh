#!/usr/bin/env bash
set -euo pipefail
umask 077
ROOT=/opt/kuaizi-remit
NGINX=/www/server/nginx/sbin/nginx
VHOST=/www/server/panel/vhost/nginx
ACME=/www/wwwroot/kuaizi-remit-acme
CERT="$ROOT/config/letsencrypt/live/kuaizi-remit-test/fullchain.pem"
KEY="$ROOT/config/letsencrypt/live/kuaizi-remit-test/privkey.pem"
exec 9>"$ROOT/certificate-setup.lock"
flock -n 9 || { echo 'Certificate setup is already running'; exit 1; }

# 已部署的站点保持现有 HTTP/HTTPS 代理，不重新覆盖为首次签发配置。
if [[ -s "$CERT" && -s "$KEY" ]]; then
  for name in api admin; do
    grep -q '^# Managed by kuaizi-remit deployment$' "$VHOST/$name.kzremit.com.conf"
  done
  "$NGINX" -t
  bash "$ROOT/scripts/renew-certificate.sh"
  sha256sum --check "$ROOT/logs/website-config-before.sha256"
  echo 'Existing certificate and origin routing preserved'
  exit 0
fi
if [[ -e "$CERT" || -e "$KEY" ]]; then
  echo 'Incomplete certificate state; inspect before replacing configuration'; exit 1
fi
install -d -m 755 "$ACME/.well-known/acme-challenge"
for name in api admin; do
  config="$VHOST/$name.kzremit.com.conf"
  if [[ -f "$config" ]] && ! grep -q '^# Managed by kuaizi-remit deployment$' "$config"; then
    echo "Refusing to replace an existing unowned site: $name"; exit 1
  fi
  cat > "$config" <<EOF
# Managed by kuaizi-remit deployment
server {
    listen 80;
    server_name $name.kzremit.com;
    location ^~ /.well-known/acme-challenge/ { root $ACME; }
    location / { return 404; }
}
EOF
done
"$NGINX" -t
"$NGINX" -s reload
if [[ -s "$ROOT/config/certbot-image" ]]; then
  CERTBOT_IMAGE=$(cat "$ROOT/config/certbot-image")
else
  docker pull certbot/certbot:latest
  CERTBOT_IMAGE=$(docker inspect --format '{{index .RepoDigests 0}}' certbot/certbot:latest)
  printf '%s\n' "$CERTBOT_IMAGE" > "$ROOT/config/certbot-image"
fi
docker run --rm --cpus=0.5 --memory=512m \
  -v "$ACME:/var/www/acme" -v "$ROOT/config/letsencrypt:/etc/letsencrypt" \
  "$CERTBOT_IMAGE" certonly --webroot -w /var/www/acme --cert-name kuaizi-remit-test \
  -d api.kzremit.com -d admin.kzremit.com --non-interactive --agree-tos --register-unsafely-without-email
for name in api admin; do
  port=8688; [[ "$name" == admin ]] && port=8689
  cat > "$VHOST/$name.kzremit.com.conf" <<EOF
# Managed by kuaizi-remit deployment
server {
    listen 80;
    server_name $name.kzremit.com;
    location ^~ /.well-known/acme-challenge/ { root $ACME; }
    location / { return 301 https://\$host\$request_uri; }
}
server {
    listen 443 ssl;
    server_name $name.kzremit.com;
    ssl_certificate $ROOT/config/letsencrypt/live/kuaizi-remit-test/fullchain.pem;
    ssl_certificate_key $ROOT/config/letsencrypt/live/kuaizi-remit-test/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    client_max_body_size 10m;
    location / {
        proxy_pass http://127.0.0.1:$port;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For \$remote_addr;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 120s;
    }
}
EOF
done
"$NGINX" -t
"$NGINX" -s reload
printf '%s\n' completed > "$ROOT/logs/tls.done"
