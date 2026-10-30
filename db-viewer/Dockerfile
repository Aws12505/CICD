# syntax=docker/dockerfile:1
# The control plane's database viewer: Adminer, signed in by the control
# plane (index.php), reaching each client server's MySQL through the terminal
# gateway's SSH tunnels. Not public: the host nginx proxies /db-viewer/ to it,
# and it answers nothing useful without a ticket the control plane issued.

FROM mlocati/php-extension-installer:2 AS extension-installer

FROM php:8.4-cli-alpine
COPY --from=extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions mysqli \
 && rm /usr/local/bin/install-php-extensions \
 && printf 'display_errors = Off\nlog_errors = On\nexpose_php = Off\nupload_max_filesize = 200M\npost_max_size = 200M\nmemory_limit = 512M\nmax_execution_time = 600\n' \
      > "$PHP_INI_DIR/conf.d/db-viewer.ini"

# Pinned and checksummed: a changed file at that URL fails the build.
ADD --checksum=sha256:7526b56dc5ecee5b5cc88206ac8f3a757392c1b991838efa71bf9f7abbb8172e \
    https://github.com/vrana/adminer/releases/download/v6.1.2/adminer-6.1.2-mysql-en.php /app/adminer.php
COPY index.php /app/index.php
RUN chmod 444 /app/adminer.php /app/index.php

ENV PHP_CLI_SERVER_WORKERS=4
USER nobody
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
  CMD wget -q -O /dev/null "http://127.0.0.1:8080/db-viewer/?file=logo.svg" || exit 1

CMD ["php", "-S", "0.0.0.0:8080", "/app/index.php"]
