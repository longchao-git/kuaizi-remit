#!/usr/bin/env bash
set -euo pipefail
umask 077
test "$(uname -m)" = x86_64
install -d -m 700 /opt/kuaizi-remit/downloads
cd /opt/kuaizi-remit/downloads
if ! command -v docker >/dev/null; then dnf -y install docker; systemctl enable --now docker; fi
if ! docker compose version >/dev/null 2>&1; then
  curl --fail --location --silent --show-error --retry 2 --connect-timeout 10 --max-time 180 \
    https://github.com/docker/compose/releases/download/v2.39.4/docker-compose-linux-x86_64 -o docker-compose-linux-x86_64
  printf '%s\n' '7af95166a730b87e172d4fc9aefea8725d3c6c7327d59149267b452114ddb7d4  docker-compose-linux-x86_64' | sha256sum --check -
  install -d -m 755 /usr/local/lib/docker/cli-plugins
  install -m 755 docker-compose-linux-x86_64 /usr/local/lib/docker/cli-plugins/docker-compose
fi
docker compose version
docker version --format '{{.Server.Version}}'
printf '%s\n' completed > /opt/kuaizi-remit/logs/compose-install.done
