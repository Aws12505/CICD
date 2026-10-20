#!/bin/bash
set -e

echo "🚀 Starting LCPortal CI/CD worker..."

mkdir -p \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/framework/cache/data \
  /var/www/html/storage/logs/pipeline-runs \
  /var/www/html/bootstrap/cache

touch /var/www/html/storage/logs/laravel.log

# Same lock and rule as the backend: it shares this directory and starts at
# the same moment.
(
  flock -x 9
  AUTOLOAD=/var/www/html/vendor/autoload.php
  if [ ! -f "$AUTOLOAD" ] || [ /var/www/html/composer.lock -nt "$AUTOLOAD" ]; then
    echo "📦 Composer packages are missing or out of date — installing."
    if ! composer install --no-dev --optimize-autoloader --no-interaction; then
      echo "⚠️  composer install failed. Continuing, but anything using a missing package will error."
    fi
    touch "$AUTOLOAD" 2>/dev/null || true
  fi
) 9>/var/www/html/storage/.boot-deps.lock

# Same fill-in as the backend (same lock): Reverb starts from this container
# a moment later and needs its credentials already in .env.
if [ -f /var/www/html/vendor/autoload.php ]; then
  (flock -x 9; php artisan control-plane:ensure-env) 9>/var/www/html/storage/.boot-env.lock || true
fi

php artisan config:clear || true

# The worker runs pipeline shell commands that need to write to /root/core/apps
# and talk to the Docker socket, so storage stays writable but we run as root.
mkdir -p /run/supervisor
chmod 755 /run /run/supervisor || true

echo "✅ Worker ready."
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
