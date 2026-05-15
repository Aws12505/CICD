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

# Composer deps (in case vendor is not committed)
if [ ! -d /var/www/html/vendor ]; then
  composer install --no-dev --optimize-autoloader --no-interaction
fi

# Build the React/Inertia frontend (production build, not dev)
npm install --no-audit --no-fund
npm run build

# App key if missing
php artisan key:generate --force || true

# Migrate + seed the admin user
php artisan migrate --force || true
php artisan db:seed --class=AdminUserSeeder --force || true

# Storage symlink
if [ ! -L /var/www/html/public/storage ]; then
  php artisan storage:link || true
fi

# Cache config/routes/views for production
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true

# Writable paths owned by www-data
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache || true
find /var/www/html/storage /var/www/html/bootstrap/cache -type d -exec chmod 775 {} \; || true
find /var/www/html/storage /var/www/html/bootstrap/cache -type f -exec chmod 664 {} \; || true

echo "✅ Backend ready."
exec apache2-foreground
