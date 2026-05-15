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

if [ ! -d /var/www/html/vendor ]; then
  composer install --no-dev --optimize-autoloader --no-interaction
fi

php artisan config:clear || true

# The worker runs pipeline shell commands that need to write to /root/core/apps
# and talk to the Docker socket, so storage stays writable but we run as root.
mkdir -p /run/supervisor
chmod 755 /run /run/supervisor || true

echo "✅ Worker ready."
exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
