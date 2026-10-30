#!/bin/sh
# Control-plane entrypoint for a Laravel image. Runs as www-data.
#
#   (no arguments)   serve HTTP with Apache
#   anything else    run it, e.g. `sh -c "php artisan queue:work"`
#                    (every process in the catalog starts this way)
set -e

cd /var/www/html

# storage/app is a host directory the control plane owns as www-data; the
# rest of storage lives in the image.
mkdir -p storage/app/public

# Configuration is read from the environment each start, so it is never
# cached. Routes, views and events are code, so caching them is safe and
# saves work on every request.
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:cache >/dev/null 2>&1 || php artisan route:clear >/dev/null 2>&1 || true
php artisan view:cache >/dev/null 2>&1 || true
php artisan event:cache >/dev/null 2>&1 || true

if [ ! -L public/storage ]; then
  php artisan storage:link >/dev/null 2>&1 || true
fi

if [ "$#" -eq 0 ]; then
  exec apache2-foreground
fi

exec "$@"
