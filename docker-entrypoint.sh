#!/bin/sh
# The control plane's entrypoint: one image, a role per container.
#
#   web        Apache, serving the application
#   queue      a queue worker (builds, releases, backups...)
#   scheduler  the scheduler (metrics, drift, backups, due releases)
#   reverb     the websocket server for live logs
#   migrate    one-off before the others: the app key if missing, Reverb
#              credentials if missing, migrations and the first admin
set -e

cd /var/www/html

# storage/ is a host directory kept across updates; make sure its layout and
# the log exist and that Apache's workers (www-data) can write to it.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs/pipeline-runs bootstrap/cache
touch storage/logs/laravel.log
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

role="${1:-web}"

case "$role" in
  migrate)
    # APP_KEY: ONLY when genuinely absent. Every encrypted column (server SSH
    # keys, stack secrets) depends on it; regenerating it loses them all.
    if grep -qE '^APP_KEY=.+' .env 2>/dev/null; then
      echo "APP_KEY present, leaving it alone."
    else
      echo "APP_KEY missing, generating one."
      php artisan key:generate --force
    fi
    php artisan control-plane:ensure-env || true
    # The database may still be starting (or restarting with the host): wait
    # for it rather than failing, since every other container waits on this.
    tries=0
    until php artisan migrate --force; do
      tries=$((tries + 1))
      [ "$tries" -ge 30 ] && { echo "The database never answered."; exit 1; }
      echo "Waiting for the database ($tries)..."
      sleep 5
    done
    php artisan db:seed --class=AdminUserSeeder --force || true
    exit 0
    ;;
  web)
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    [ -L public/storage ] || php artisan storage:link || true
    exec apache2-foreground
    ;;
  queue)
    php artisan config:cache
    exec php artisan queue:work --sleep=3 --tries=1 --max-time=3600 --timeout=3600 --memory=1024
    ;;
  scheduler)
    php artisan config:cache
    exec php artisan schedule:work
    ;;
  reverb)
    php artisan config:cache
    exec php artisan reverb:start --host=0.0.0.0 --port=8080
    ;;
  *)
    exec "$@"
    ;;
esac
