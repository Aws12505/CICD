# syntax=docker/dockerfile:1
# Control-plane default image for a Laravel service.
#
# The code is copied in and dependencies installed at build time: the image
# is complete, and client servers only ever pull it.
#
# One image serves every role. With no arguments it serves HTTP; given a
# command (a process from the catalog, e.g. `php artisan queue:work`) it runs
# that instead. Each process is its own container, and all of them run as
# www-data, never root.
#
# Composer, git and Node exist only in the build stages; the final image is
# the shared PHP base plus the application.

# The PHP, its extensions and Apache come from the control plane's shared
# base image (resources/build-recipes/laravel/base.Dockerfile), which the
# builder makes once and passes in. The default names an image that does not
# exist, on purpose: building this file by hand without the argument fails
# loudly instead of producing an image with no extensions.
ARG PHP_BASE=php-base-not-provided

# ----------------------------------------------------------------- build ----
FROM ${PHP_BASE} AS build

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*

# Dependencies first, so a code-only change reuses this layer.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-interaction

# ---------------------------------------------------------------- assets ----
# Built on top of the build stage, not in a bare Node image: Vite plugins such
# as Wayfinder run `php artisan …` during the build, which needs PHP and
# vendor/. Node is copied in for this stage only.
FROM build AS assets
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

# ------------------------------------------------------------------- app ----
# What the server needs and nothing else: no tests, front-end sources, Node
# files or environment files.
FROM build AS app
RUN rm -rf .env .env.* tests node_modules resources/js resources/css \
      package.json package-lock.json pnpm-lock.yaml yarn.lock vite.config.* \
      .control-plane phpunit.xml .editorconfig .gitattributes \
 && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
      storage/framework/views storage/logs bootstrap/cache

# ----------------------------------------------------------------- final ----
FROM ${PHP_BASE}

COPY --from=app --chown=www-data:www-data /var/www/html /var/www/html
COPY --from=assets --chown=www-data:www-data /var/www/html/public /var/www/html/public
COPY --chmod=755 .control-plane/entrypoint.sh /usr/local/bin/control-plane-entrypoint

USER www-data
EXPOSE 80

# The catalog's health path for the web container; process containers turn
# the check off in their compose block.
ARG HEALTH_PATH=/up
ENV HEALTH_PATH=${HEALTH_PATH}
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl -fsS -o /dev/null "http://127.0.0.1${HEALTH_PATH}" || exit 1

ENTRYPOINT ["control-plane-entrypoint"]
