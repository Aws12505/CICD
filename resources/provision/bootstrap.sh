#!/usr/bin/env bash
#
# Prepares a fresh Ubuntu or Debian server to host client stacks.
#
# Run by the control plane over SSH, as root. Safe to run again at any time:
# every step checks before it changes anything, so re-running it on a working
# server changes nothing.
#
# Expects in the environment:
#   REGISTRY_HOST, REGISTRY_USER, REGISTRY_TOKEN   read-only image pull access
#   SSH_PORT                                       the port the CP connects on
#   ALLOW_SSH_FROM                                 optional, space-separated IPs
#
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive

step() { printf '\n==> %s\n' "$*"; }

if [ "$(id -u)" -ne 0 ]; then
  echo "This must run as root. Paste the authorize command as root, and set the server's SSH user to root."
  exit 1
fi

. /etc/os-release
case "${ID}" in
  ubuntu|debian) ;;
  *) echo "Unsupported OS: ${ID}. Use Ubuntu 22.04/24.04 or Debian 12."; exit 1 ;;
esac

step "Installing base packages"
apt-get update -y -q
apt-get install -y -q ca-certificates curl gnupg nginx certbot python3-certbot-nginx rsync ufw unattended-upgrades jq

step "Installing Docker"
if ! command -v docker >/dev/null 2>&1; then
  install -m 0755 -d /etc/apt/keyrings
  curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
  chmod a+r /etc/apt/keyrings/docker.asc
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" \
    > /etc/apt/sources.list.d/docker.list
  apt-get update -y -q
  apt-get install -y -q docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
fi
systemctl enable --now docker >/dev/null
docker --version
docker compose version

step "Firewall"
# Allow SSH only from the address this very session comes from — the control
# plane. Read from the live connection rather than configured, so a wrong
# setting can never lock the control plane out. The hosting provider's web
# console remains a way in regardless.
CP_IP="${SSH_CLIENT%% *}"
ufw allow 80/tcp >/dev/null
ufw allow 443/tcp >/dev/null
if [ -n "${CP_IP}" ]; then
  ufw allow from "${CP_IP}" to any port "${SSH_PORT:-22}" proto tcp >/dev/null
  echo "SSH allowed from ${CP_IP} only"
else
  ufw allow "${SSH_PORT:-22}/tcp" >/dev/null
  echo "Could not read the connecting address; SSH left open to all"
fi
# Extra addresses from Settings (an office, a second control-plane address).
for ip in ${ALLOW_SSH_FROM:-}; do
  ufw allow from "${ip}" to any port "${SSH_PORT:-22}" proto tcp >/dev/null
  echo "SSH also allowed from ${ip}"
done
ufw --force enable >/dev/null
# Containers publish only on 127.0.0.1 and the shared infra publishes nothing,
# so Docker's habit of bypassing ufw for published ports has nothing to expose.

step "Docker network for the shared MySQL, Redis and NATS"
docker network inspect pizza_shared >/dev/null 2>&1 || docker network create pizza_shared

step "Directories"
mkdir -p /srv/_infra/mysql /srv/_infra/redis /srv/_infra/nats /var/www/acme /var/www/maintenance
if [ ! -f /var/www/maintenance/index.html ]; then
  cat > /var/www/maintenance/index.html <<'HTML'
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scheduled maintenance</title>
<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;background:#f7f8f6;color:#12161c}main{max-width:32rem;padding:2rem;text-align:center}</style></head>
<body><main><h1>Scheduled maintenance</h1><p>We are applying an update. This page will go away on its own in a few minutes.</p></main></body></html>
HTML
fi

step "nginx"
rm -f /etc/nginx/sites-enabled/default
# Long hostnames like hiringtesting.client-domain.cloud overflow the default.
echo 'server_names_hash_bucket_size 128;' > /etc/nginx/conf.d/00-control-plane.conf
nginx -t
systemctl enable --now nginx >/dev/null
systemctl reload nginx

step "Certificate renewal"
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nnginx -s reload\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
systemctl enable --now certbot.timer >/dev/null 2>&1 || true

step "Image registry"
if [ -n "${REGISTRY_TOKEN:-}" ]; then
  printf '%s' "${REGISTRY_TOKEN}" | docker login "${REGISTRY_HOST}" -u "${REGISTRY_USER}" --password-stdin
else
  echo "No registry pull token configured — set it in Settings before deploying."
fi

step "Done"
uname -sr
free -h | head -2
df -h / | tail -1
