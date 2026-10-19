FROM php:8.4-apache

# Laravel needs rewrite + common headers
RUN a2enmod rewrite headers expires

# OS deps for common PHP extensions
RUN apt-get update && apt-get install -y \
    git zip unzip curl ca-certificates gnupg openssh-client rsync \
    libicu-dev \
    libzip-dev \
    libpng-dev libjpeg-dev libfreetype6-dev libwebp-dev \
    libonig-dev \
    libxml2-dev \
    libxslt1-dev \
    default-mysql-client \
 && rm -rf /var/lib/apt/lists/*

# GD with jpeg/freetype/webp
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp

# PHP extensions
RUN docker-php-ext-install -j"$(nproc)" \
    bcmath \
    exif \
    gd \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo \
    pdo_mysql \
    sockets \
    xsl \
    zip

# Node.js 22 (build the React/Inertia frontend)
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - \
 && apt-get install -y nodejs \
 && rm -rf /var/lib/apt/lists/*

# Docker CLI + compose plugin (pipelines run `docker compose` against the host socket)
RUN install -m 0755 -d /etc/apt/keyrings \
 && curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc \
 && chmod a+r /etc/apt/keyrings/docker.asc \
 && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/debian $(. /etc/os-release && echo $VERSION_CODENAME) stable" > /etc/apt/sources.list.d/docker.list \
 && apt-get update \
 && apt-get install -y docker-ce-cli docker-compose-plugin docker-buildx-plugin \
 && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Sensible PHP limits
RUN { \
      echo "memory_limit=1G"; \
      echo "upload_max_filesize=256M"; \
      echo "post_max_size=256M"; \
      echo "max_execution_time=600"; \
      echo "max_input_time=600"; \
    } > /usr/local/etc/php/conf.d/custom.ini

# Point Apache to Laravel /public and allow htaccess
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf \
 && sed -ri 's!/var/www/!/var/www/html/public!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '\n<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' >> /etc/apache2/apache2.conf

# Entrypoint
COPY docker-entrypoint-backend.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80
ENTRYPOINT ["docker-entrypoint.sh"]
