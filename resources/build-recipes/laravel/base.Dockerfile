# Control-plane PHP base image, shared by every Laravel service.
#
# PHP, its extensions and Composer are the slow part of a Laravel image (the
# extensions compile from source, about three minutes) and are identical for
# every service. They are built once, pushed to the registry as
# php-base:<hash of this file>, and each service's own Dockerfile starts
# FROM that. A service build then only adds its code: seconds, not minutes.
#
# Editing this file changes its hash, so the next build makes the new base
# once and every service picks it up on its next build.
# Base revision: 1   (bump to rebuild on a newer php:8.4-apache)

FROM php:8.4-apache

RUN a2enmod rewrite headers expires

RUN apt-get update && apt-get install -y --no-install-recommends \
      git zip unzip curl ca-certificates \
      libicu-dev libzip-dev libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev \
      libonig-dev libxml2-dev libxslt1-dev libldap2-dev libtidy-dev libgmp-dev \
      libmagickwand-dev libcurl4-openssl-dev default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

# The bundled extensions compile from the PHP source (a few minutes). Their own
# layer, so nothing after it can throw that work away.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" \
      bcmath calendar exif gd gettext gmp intl ldap mbstring mysqli opcache \
      pcntl pdo pdo_mysql soap sockets tidy xsl zip

# pecl.php.net is one volunteer-run server and answers 504 now and then, so
# each download is retried; a retry starts here, not at the compile above.
RUN for ext in redis imagick; do \
      n=0; \
      until pecl install "$ext"; do \
        n=$((n + 1)); \
        [ "$n" -ge 5 ] && exit 1; \
        echo "pecl install $ext failed, retry $n of 4"; \
        sleep $((n * 15)); \
      done; \
    done \
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
