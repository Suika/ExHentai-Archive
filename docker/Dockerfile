# syntax=docker/dockerfile:1
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    --ignore-platform-req='ext-*'

# Same packages plus the dev dependencies (PHPUnit), only used by the "test" stage
FROM vendor AS vendor-dev
RUN composer install --no-interaction --no-progress --prefer-dist --optimize-autoloader \
    --ignore-platform-req='ext-*'

FROM debian:trixie-slim AS app
ENTRYPOINT [ "sh", "/entrypoint.sh" ]
# Healthy when nginx, php-fpm, the database and the search index answer (api.php?action=test)
HEALTHCHECK --interval=30s --timeout=10s --start-period=5m --retries=3 \
  CMD wget --quiet --tries=1 -O - "http://localhost:${NGINX_PORT}/api.php?action=test" \
  | grep -q '"database":true,"search":true' || exit 1
WORKDIR /var/www/exhen

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update && \
    apt-get -y install --no-install-recommends \
    ca-certificates \
    wget \
    tzdata \
    gettext-base \
    nginx \
    supervisor \
    p7zip-full \
    sqlite3 \
    libvips42t64 \
    # PHP 8.4; ffi, phar, posix, pdo come with php8.4-common
    php8.4-fpm \
    php8.4-cli \
    php8.4-curl \
    php8.4-gd \
    php8.4-intl \
    php8.4-mbstring \
    php8.4-sqlite3 \
    # not used, but RedBean 5.7 reads a pdo_mysql constant when it is loaded
    php8.4-mysql \
    php8.4-xml \
    php8.4-zip && \
    rm -rf /var/lib/apt/lists/* /etc/nginx/sites-enabled/*

COPY docker/php.ini /etc/php/8.4/fpm/conf.d/99-exhentai-archive.ini
COPY docker/php.ini /etc/php/8.4/cli/conf.d/99-exhentai-archive.ini
COPY docker/php-fpm-pool.conf /etc/php/8.4/fpm/pool.d/zz-exhentai-archive.conf

COPY --chown=www-data:www-data --exclude=docker --exclude=tests --exclude=phpunit.xml.dist . /var/www/exhen/
COPY --chown=www-data:www-data --from=vendor /app/vendor /var/www/exhen/vendor

RUN mkdir -p /var/www/exhen/images /var/www/exhen/tmp /var/www/exhen/archive /var/www/exhen/data /run/php && \
    chown www-data:www-data -R /var/www/exhen

COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/entrypoint.sh docker/config.json docker/nginx.conf /

# SQLite database file (created from db.sql on the first start; import-mysql-dump.php migrates a MariaDB dump)
ENV DB_PATH=/var/www/exhen/data/exhen.sqlite \
    EX_READPERCENTAGE=80 \
    EX_ARCHDIR=/var/www/exhen/archive \
    EX_IMGDIR=/var/www/exhen/images \
    EX_TEMPDIR=/var/www/exhen/tmp \
    EX_VIEWTYPE=mpv \
    EX_MEMBERID=placehoder \
    EX_PASSHASH=placehoder \
    EX_ACCESSKEY=UGANDA \
    # 1 enables deleting galleries from the reader (api.php?action=deletegallery, needs EX_ACCESSKEY)
    EX_ALLOW_DELETE=0 \
    EX_IGNEOUS=placehoder \
    EX_SK=changeme \
    EX_SP=2 \
    EX_HATHPERKS=m1.m2.m3.tf.t1.t2.t3.p1.p2.s-210aa44613 \
    SUPERVISOR_PORT=9001 \
    NGINX_PORT=6767 \
    DOLLAR='$' \
    TZ=Europe/London \
    # libvips runs worker threads; limit glibc per-thread malloc arenas so php-fpm workers return memory
    MALLOC_ARENA_MAX=2

VOLUME /var/www/exhen/data /var/www/exhen/images /var/www/exhen/archive /var/www/exhen/tmp

# Test image: the app plus PHPUnit and the test suite (see tests/README.md).
# Build/run: cd docker && docker compose -f docker-compose.test.yml run --rm tests
FROM app AS test
# Chromium for the browser tests (tests/Browser), driven through chrome-php/chrome
RUN apt-get update && \
    apt-get -y install --no-install-recommends chromium fonts-dejavu-core && \
    rm -rf /var/lib/apt/lists/*
COPY --chown=www-data:www-data --from=vendor-dev /app/vendor /var/www/exhen/vendor
COPY --chown=www-data:www-data tests /var/www/exhen/tests
COPY --chown=www-data:www-data phpunit.xml.dist /var/www/exhen/
COPY docker/test-entrypoint.sh /test-entrypoint.sh
ENTRYPOINT [ "sh", "/test-entrypoint.sh" ]
HEALTHCHECK NONE

# The default build target stays the production image
FROM app
