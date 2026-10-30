# syntax=docker/dockerfile:1
# Control-plane PHP base image, shared by every Laravel service.
#
# PHP, its extensions and Apache are the slow part of a Laravel image and the
# same for every service. They are built once, pushed to the registry as
# php-base:<hash of this file>, and each service's own Dockerfile starts
# FROM that. A service build then only adds its code: seconds, not minutes.
#
# Lean on purpose: extensions are installed with install-php-extensions, which
# keeps only their runtime libraries. The stock php image also carries a whole
# compiler toolchain (for docker-php-ext-install); it is purged at the end and
# the result copied into one fresh layer, because a later layer can never
# shrink an earlier one. No git, composer, compiler or database client is left
# in the image; the services' own build stage brings composer.
# Imagick is not installed: no service uses it, and ImageMagick alone was a
# large share of the old image.
#
# Editing this file changes its hash, so the next build makes the new base
# once and every service picks it up on its next build.
# Base revision: 2   (bump to rebuild on a newer php:8.4-apache)

FROM mlocati/php-extension-installer:2 AS extension-installer

FROM php:8.4-apache AS php

COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# The bundled extensions (compiled from the PHP source, a few minutes). Their
# own layer, so nothing after it can throw that work away.
RUN install-php-extensions \
      bcmath calendar exif gd gettext gmp intl ldap mysqli opcache \
      pcntl pdo_mysql soap sockets tidy xsl zip

# phpredis comes from PECL, one volunteer-run server that answers 504 now and
# then: retried, in its own layer, so a retry never repeats the compile above.
RUN for n in 1 2 3 4 5; do \
      install-php-extensions redis && break; \
      [ "$n" -ge 5 ] && exit 1; \
      echo "installing redis failed, retry $n of 4"; \
      sleep $((n * 15)); \
    done \
 && rm -rf /tmp/* /var/lib/apt/lists/* /usr/src/php*

# Production PHP defaults, then ours. Memory and worker counts are read from
# the environment at start, so a service can raise them in its settings
# without a rebuild; these are the defaults (today's values).
ENV PHP_MEMORY_LIMIT=2G \
    APACHE_MAX_WORKERS=150
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && { \
      echo 'memory_limit = ${PHP_MEMORY_LIMIT}'; \
      echo 'upload_max_filesize = 1G'; \
      echo 'post_max_size = 1G'; \
      echo 'max_execution_time = 600'; \
      echo 'max_input_time = 600'; \
      echo 'expose_php = Off'; \
      echo 'realpath_cache_size = 4096K'; \
      echo 'realpath_cache_ttl = 600'; \
      echo 'opcache.enable = 1'; \
      echo 'opcache.enable_cli = 0'; \
      echo 'opcache.memory_consumption = 256'; \
      echo 'opcache.interned_strings_buffer = 32'; \
      echo 'opcache.max_accelerated_files = 50000'; \
      echo 'opcache.validate_timestamps = 0'; \
    } > "$PHP_INI_DIR/conf.d/zz-control-plane.ini"

# Apache: prefork with mod_php, as before, on port 80 but as www-data rather
# than root (Docker lets any user bind low ports inside a container, so the
# port every stack already maps stays). Each worker is one PHP process and holds at most
# one request's database connections; the control plane sizes MySQL to the
# sum of every container's APACHE_MAX_WORKERS. A worker is replaced after
# 1000 requests, so a leak cannot grow without bound.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN a2enmod rewrite headers expires \
 && sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '\n<Directory ${APACHE_DOCUMENT_ROOT}>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf \
 && printf '<IfModule mpm_prefork_module>\n    StartServers 2\n    MinSpareServers 2\n    MaxSpareServers 10\n    ServerLimit ${APACHE_MAX_WORKERS}\n    MaxRequestWorkers ${APACHE_MAX_WORKERS}\n    MaxConnectionsPerChild 1000\n</IfModule>\n' > /etc/apache2/mods-available/mpm_prefork.conf \
 && sed -ri 's/^ServerTokens .*/ServerTokens Prod/; s/^ServerSignature .*/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
 && chown -R www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2 /var/www/html

WORKDIR /var/www/html

# The required extensions, checked when the base is built (and again for
# every service image before it is pushed).
RUN php -m | tr '[:upper:]' '[:lower:]' > /tmp/modules \
 && for ext in bcmath gd intl mbstring mysqli opcache pcntl pdo_mysql redis sockets zip; do \
      grep -qx "$ext" /tmp/modules || grep -qx "zend $ext" /tmp/modules || { echo "missing extension: $ext"; exit 1; }; \
    done \
 && rm /tmp/modules

# The toolchain goes. Compilers, binutils and -dev packages are marked as
# automatically installed (the stock image marks them manual) and removed with
# whatever only they needed; the extensions' runtime libraries are manual, so
# they stay. Checked again right after.
RUN apt-mark auto $(dpkg-query -W -f '${Package}\n' \
      | grep -E '^(gcc|g\+\+|cpp|binutils|libbinutils|libc6-dev|libc-dev-bin|libcrypt-dev|linux-libc-dev|libgcc-[0-9]+-dev|libstdc\+\+-[0-9]+-dev|dpkg-dev|make|autoconf|re2c|pkgconf|pkg-config|file$)' \
      | grep -v -- '-base$') >/dev/null \
 && apt-get autoremove --purge -y \
 && rm -rf /usr/src/* /usr/local/bin/install-php-extensions /var/lib/apt/lists/* /tmp/* \
 && php -m | grep -qix redis && php -m | grep -qix intl && php -m | grep -qix gd \
 && apache2ctl -t

# ----------------------------------------------------------------- final ----
# One layer holding only what the purge left.
FROM scratch
COPY --from=php / /

ENV PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    PHP_INI_DIR=/usr/local/etc/php \
    APACHE_CONFDIR=/etc/apache2 \
    APACHE_ENVVARS=/etc/apache2/envvars \
    APACHE_DOCUMENT_ROOT=/var/www/html/public \
    PHP_MEMORY_LIMIT=2G \
    APACHE_MAX_WORKERS=150

WORKDIR /var/www/html
EXPOSE 80
STOPSIGNAL SIGWINCH
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["apache2-foreground"]
