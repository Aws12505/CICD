# Control-plane default image for a Laravel service.
#
# The code is copied in and dependencies installed at build time: the image
# is complete, and client servers only ever pull it. Derived from the images
# already proven in production (php 8.4 + apache, the same extensions).
#
# One image serves every role. With no arguments it serves HTTP; given a
# command (a process from the catalog, e.g. `php artisan queue:work`) it runs
# that instead, as www-data. Each process is its own container.

# The PHP, its extensions and Composer come from the control plane's shared
# base image (resources/build-recipes/laravel/base.Dockerfile), which the
# builder makes once and passes in. The default names an image that does not
# exist, on purpose: building this file by hand without the argument fails
# loudly instead of producing an image with no extensions.
ARG PHP_BASE=php-base-not-provided

# ------------------------------------------------------------------- app ----
FROM ${PHP_BASE} AS app

# Dependencies first, so a code-only change reuses this layer.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && rm -f .env

# ---------------------------------------------------------------- assets ----
# Built on top of the app, not in a bare Node image: Vite plugins such as
# Wayfinder run `php artisan …` during the build, which needs PHP and vendor/.
# Node is copied in for this stage only and never reaches the final image.
FROM app AS assets
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s ../lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
# Only services with a Vite front end build assets; the rest skip this.
# BUILD_ASSETS=0 comes from the service's catalog when it is an API only.
ARG BUILD_ASSETS=1
RUN if [ "$BUILD_ASSETS" = "1" ] && [ -f package-lock.json ] && ls vite.config.* >/dev/null 2>&1; then \
      npm ci --no-audit --no-fund && npm run build; \
    else \
      mkdir -p public; \
    fi

# ----------------------------------------------------------------- final ----
FROM app

COPY --from=assets /var/www/html/public ./public
RUN chown -R www-data:www-data storage bootstrap/cache

COPY .control-plane/entrypoint.sh /usr/local/bin/control-plane-entrypoint
RUN chmod +x /usr/local/bin/control-plane-entrypoint

EXPOSE 80
ENTRYPOINT ["control-plane-entrypoint"]
