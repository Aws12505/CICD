# syntax=docker/dockerfile:1
# The control plane's image: one immutable image, every process a container
# of it with a different role (web, queue, scheduler, reverb, migrate).
#
# The code, its Composer packages and the built front end are all in the
# image; nothing is installed when a container starts, so a restart is fast
# and a registry hiccup can never take the control plane down. Updating is
# still `git pull` then `docker compose up -d --build`.

FROM mlocati/php-extension-installer:2 AS extension-installer
FROM docker:27-cli AS docker-cli

# ------------------------------------------------------------------ base ----
FROM php:8.4-apache AS base

COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions bcmath exif gd intl opcache pcntl pdo_mysql sockets xsl zip \
 && rm /usr/local/bin/install-php-extensions

# What the control plane drives: git for checkouts, ssh/rsync for client
# servers, the MySQL client for its own backup, and Docker to build images.
RUN apt-get update \
 && apt-get install -y --no-install-recommends git openssh-client rsync default-mysql-client \
 && rm -rf /var/lib/apt/lists/*
COPY --from=docker-cli /usr/local/bin/docker /usr/local/bin/docker
COPY --from=docker-cli /usr/local/libexec/docker/cli-plugins /usr/local/libexec/docker/cli-plugins

RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'memory_limit = 1G'; \
      echo 'upload_max_filesize = 256M'; \
      echo 'post_max_size = 256M'; \
      echo 'max_execution_time = 600'; \
      echo 'max_input_time = 600'; \
      echo 'expose_php = Off'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.memory_consumption = 192'; \
      echo 'opcache.validate_timestamps = 0'; \
    } > "$PHP_INI_DIR/conf.d/zz-control-plane.ini"

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite headers expires \
 && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '\n<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf

WORKDIR /var/www/html

# ----------------------------------------------------------------- build ----
FROM base AS build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apt-get update && apt-get install -y --no-install-recommends unzip && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist --no-progress
COPY . .
# storage/ is left out of the context (it lives on the host); Artisan still
# needs its layout during the build (Wayfinder below).
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions \
      storage/framework/views storage/logs bootstrap/cache

# ---------------------------------------------------------------- assets ----
# On top of the build stage: Wayfinder's Vite plugin runs `php artisan`.
# The Reverb values are baked into the front end, so they come in as build
# arguments from .env (docker-compose.yml passes them).
FROM build AS assets
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s ../lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm
ARG VITE_APP_NAME="Control plane"
ARG VITE_REVERB_APP_KEY=""
ARG VITE_REVERB_HOST=""
ARG VITE_REVERB_PORT="443"
ARG VITE_REVERB_SCHEME="https"
RUN npm ci --no-audit --no-fund && npm run build

# ----------------------------------------------------------------- final ----
FROM base
COPY --from=build /var/www/html /var/www/html
COPY --from=assets /var/www/html/public/build /var/www/html/public/build
RUN rm -rf node_modules tests terminal-gateway db-viewer \
 && mkdir -p storage bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache
COPY --chmod=755 docker-entrypoint.sh /usr/local/bin/control-plane-entrypoint

EXPOSE 80
ENTRYPOINT ["control-plane-entrypoint"]
CMD ["web"]
