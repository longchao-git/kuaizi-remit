#!/usr/bin/env bash
set -euo pipefail
umask 077
ROOT=/opt/kuaizi-remit
VHOST=/www/server/panel/vhost/nginx
test -f "$ROOT/config/cloudflare-edge.conf"
install -m 644 "$ROOT/config/cloudflare-edge.conf" "$VHOST/00-kuaizi-remit-edge.conf"
python3 - <<'PY'
from pathlib import Path

root = Path('/opt/kuaizi-remit')
vhost = Path('/www/server/panel/vhost/nginx')
marker = '# Managed by kuaizi-remit deployment'
for name, port in [('api', 8688), ('admin', 8689)]:
    path = vhost / f'{name}.kzremit.com.conf'
    text = path.read_text()
    if not text.startswith(marker):
        raise RuntimeError(f'Refusing to edit an unowned vhost: {name}')
    backup = root / 'data' / 'backups' / f'{name}-before-cloudflare-http.conf'
    if not backup.exists():
        backup.write_text(text)
    old = 'location / { return 301 https://$host$request_uri; }'
    replacement = f'''location / {{
        if ($kuaizi_cloudflare_peer = 0) {{ return 403; }}
        if ($kuaizi_edge_https = 0) {{ return 301 https://$host$request_uri; }}
        proxy_pass http://127.0.0.1:{port};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto https;
        proxy_set_header X-Forwarded-For $kuaizi_client_ip;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 120s;
    }}'''
    if old in text:
        text = text.replace(old, replacement, 1)
    elif '$kuaizi_cloudflare_peer' not in text:
        raise RuntimeError(f'Unexpected HTTP vhost configuration: {name}')
    text = text.replace('proxy_set_header X-Forwarded-For $remote_addr;', 'proxy_set_header X-Forwarded-For $kuaizi_client_ip;')
    text = text.replace('proxy_set_header X-Forwarded-For $http_cf_connecting_ip;', 'proxy_set_header X-Forwarded-For $kuaizi_client_ip;')
    path.write_text(text)
PY
/www/server/nginx/sbin/nginx -t
/www/server/nginx/sbin/nginx -s reload
sha256sum --check "$ROOT/logs/website-config-before.sha256"
date -u +%FT%TZ > "$ROOT/config/cloudflare-http-origin.enabled"
