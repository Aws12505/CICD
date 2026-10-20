#!/bin/bash
set -e

echo "🚀 Starting LCPortal CI/CD backend..."

mkdir -p \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/framework/cache/data \
  /var/www/html/storage/logs/pipeline-runs \
  /var/www/html/bootstrap/cache

touch /var/www/html/storage/logs/laravel.log

# Composer deps. vendor/ is not committed, and it has to be refreshed whenever
# composer.lock moves — checking only that the directory exists meant a new
# package (phpseclib, for server keys) was never installed on a server that
# already had a vendor/ from before.
#
# The backend and the worker share this directory and start together, so the
# install runs under a lock: whichever gets it first installs, the other waits
# and then finds nothing to do.
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

# Build the React/Inertia frontend, but only when it is actually stale.
# This used to run on every boot, adding minutes to each restart and turning a
# transient npm registry failure into a failed deploy.
FRONTEND_MANIFEST=/var/www/html/public/build/manifest.json
if [ ! -f "$FRONTEND_MANIFEST" ] \
   || [ /var/www/html/package-lock.json -nt "$FRONTEND_MANIFEST" ] \
   || [ /var/www/html/package.json -nt "$FRONTEND_MANIFEST" ]; then
  echo "📦 Frontend build is missing or stale — rebuilding."
  # A failed build must not kill the container: with `set -e` it used to, and
  # Docker then restarted it forever, hiding the actual npm error. Keep the
  # old build (if there is one) serving and say plainly what happened.
  if ! (npm install --no-audit --no-fund && npm run build); then
    echo "⚠️  The frontend build FAILED (output above)."
    if [ -f "$FRONTEND_MANIFEST" ]; then
      echo "⚠️  Serving the previous build. Fix it with: docker exec CICD sh -c 'npm install && npm run build'"
    else
      echo "⚠️  There is no previous build, so pages will not load until this is fixed:"
      echo "⚠️    docker exec CICD sh -c 'npm install && npm run build'"
    fi
  fi
else
  echo "📦 Frontend build is current — skipping."
fi

# App key — ONLY when genuinely absent.
#
# This used to run unconditionally. APP_KEY is what Laravel's `encrypted` casts
# use, so regenerating it makes every encrypted column permanently unreadable:
# server SSH private keys, stack secrets, everything. A plain `docker compose
# restart` was enough to lose them all. Never make this unconditional again.
if grep -qE '^APP_KEY=.+' /var/www/html/.env 2>/dev/null; then
  echo "🔑 APP_KEY present — leaving it alone."
else
  echo "🔑 APP_KEY missing — generating one."
  php artisan key:generate --force
fi

# Migrate + seed the admin user
php artisan migrate --force || true
php artisan db:seed --class=AdminUserSeeder --force || true

# Storage symlink
if [ ! -L /var/www/html/public/storage ]; then
  php artisan storage:link || true
fi

# Cache config/routes/views for production.
# This block used to say "cache" and call `:clear`, so nothing was ever cached.
# Clear first so a stale cache from a previous image can never be reused, then
# build fresh ones. Each is `|| true`: an uncached boot is slower but working,
# and a failure here must not stop the container coming up.
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

php artisan config:cache || true
php artisan route:cache || true
php artisan view:cache || true

# Writable paths owned by www-data
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
find /var/www/html/storage /var/www/html/bootstrap/cache -type d -exec chmod 775 {} \; || true
find /var/www/html/storage /var/www/html/bootstrap/cache -type f -exec chmod 664 {} \; || true

echo "✅ Backend ready."
exec apache2-foreground
