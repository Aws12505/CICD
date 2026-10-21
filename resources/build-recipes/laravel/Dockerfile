# Control-plane default image for a Laravel service.
#
# The code is copied in and dependencies installed at build time: the image
# is complete, and client servers only ever pull it. Derived from the images
# already proven in production (php 8.4 + apache, the same extensions).
#
# One image serves every role. With no arguments it serves HTTP; given a
# command (a process from the catalog, e.g. `php artisan queue:work`) it runs
# that instead, as www-data. Each process is its own container.

# ---------------------------------------------------------------- assets ----
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY . .
# Only services with a Vite front end build assets; the rest skip this.
RUN if [ -f package-lock.json ] && ls vite.config.* >/dev/null 2>&1; then \
      npm ci --no-audit --no-fund && npm run build; \
    else \
      mkdir -p public; \
    fi

# ------------------------------------------------------------------- app ----
FROM php:8.4-apache

RUN a2enmod rewrite headers expires

RUN apt-get update && apt-get install -y --no-install-recommends \
      git zip unzip curl ca-certificates \
      libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev \
      libonig-dev libxml2-dev libxslt1-dev libldap2-dev libtidy-dev libgmp-dev \
      libmagickwand-dev libcurl4-openssl-dev default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" \
      bcmath calendar exif gd gettext gmp intl ldap mbstring mysqli opcache \
      pcntl pdo pdo_mysql soap sockets tidy xsl zip \
 && pecl install redis imagick \
 && docker-php-ext-enable redis imagick

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN { \
      echo "memory_limit=2G"; \
      echo "upload_max_filesize=1G"; \
      echo "post_max_size=1G"; \
      echo "max_execution_time=600"; \
      echo "max_input_time=600"; \
      echo "opcache.enable=1"; \
      echo "opcache.validate_timestamps=0"; \
    } > /usr/local/etc/php/conf.d/control-plane.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '\n<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Dependencies first, so a code-only change reuses this layer.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY . .
COPY --from=assets /app/public ./public
RUN composer dump-autoload --optimize --no-dev --no-interaction \
 && rm -f .env \
 && chown -R www-data:www-data storage bootstrap/cache

COPY .control-plane/entrypoint.sh /usr/local/bin/control-plane-entrypoint
RUN chmod +x /usr/local/bin/control-plane-entrypoint

EXPOSE 80
ENTRYPOINT ["control-plane-entrypoint"]
