#!/bin/bash
# Control-plane entrypoint for a Laravel image.
#
#   (no arguments)   serve HTTP with apache
#   anything else    run it as www-data, e.g. `sh -c "php artisan queue:work"`
#                    (every process in the catalog starts this way)
set -e

cd /var/www/html

# storage/app is a host bind mount; the rest of storage lives in the image.
mkdir -p \
  storage/app/public \
  storage/framework/sessions \
  storage/framework/views \
  storage/framework/cache/data \
  storage/logs \
  bootstrap/cache
touch storage/logs/laravel.log
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# Configuration comes from the environment at run time, so nothing cached at
# build time may survive into it.
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true

if [ ! -L public/storage ]; then
  php artisan storage:link >/dev/null 2>&1 || true
fi

if [ "$#" -eq 0 ]; then
  exec apache2-foreground
fi

exec runuser -u www-data -- "$@"
