# HUBzero CMS runtime image.
#
# This image deliberately contains NO application source. The CMS is checked out
# into /var/www/html at container start (see hub-source-sync) so the hub can be
# updated with a git pull instead of an image rebuild.

FROM php:8.2-apache

# PHP 8.2.16 fixed the php-fpm PATH_INFO bug that core/bootstrap/app.php works
# around; anything older takes a slower, buggier code path.
RUN set -eux; \
    php -r 'exit(PHP_VERSION_ID >= 80216 ? 0 : 1);'

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/var/cache/composer \
    APACHE_DOCUMENT_ROOT=/var/www/html

# Tools that must survive into the final image.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        git \
        gosu \
        mariadb-client \
        unzip \
        zip \
    ; \
    rm -rf /var/lib/apt/lists/*

# core/bin/muse is hardcoded to #!/usr/bin/php, but the official PHP images
# install the binary at /usr/local/bin/php. The admin UI shells out to muse by
# path (com_installer's Cli::call), so without this every one of those calls
# dies with "not found", produces no stdout, and the UI reports the unhelpful
# "MUSE <command> returned NULL" -- which breaks custom extension installs,
# repository status and update checks alike.
RUN ln -sf /usr/local/bin/php /usr/bin/php

# Build the PHP extensions, then throw away the toolchain but keep the shared
# libraries the extensions actually link against. Purging the -dev packages
# with --auto-remove alone takes libpng/libicu/libzip with them and every
# extension fails to load at startup.
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libldap2-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
    ; \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-configure ldap --with-libdir=lib/"$(dpkg-architecture --query DEB_BUILD_MULTIARCH)"; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        ldap \
        mysqli \
        opcache \
        pdo_mysql \
        sockets \
        zip \
    ; \
    pecl install apcu redis; \
    docker-php-ext-enable apcu redis; \
    \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark; \
    ldd /usr/local/lib/php/extensions/*/*.so \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) next; gsub("^/(usr/)?", "", so); printf "*/%s\n", so }' \
        | sort -u \
        | xargs -r dpkg-query --search 2>/dev/null \
        | cut -d: -f1 \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    \
    php -m | grep -qx gd; \
    php -m | grep -qx intl; \
    php -m | grep -qx zip; \
    php -m | grep -qx pdo_mysql

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN set -eux; \
    a2enmod rewrite headers expires deflate ssl; \
    a2dissite 000-default

COPY docker/apache/hubzero.conf        /etc/apache2/sites-available/hubzero.conf
COPY docker/apache/hubzero-common.conf /etc/apache2/conf-available/hubzero-common.conf
COPY docker/php/hubzero.ini            /usr/local/etc/php/conf.d/zz-hubzero.ini
COPY docker/bin/                       /usr/local/lib/hubzero/

RUN set -eux; \
    a2ensite hubzero; \
    a2enconf hubzero-common; \
    chmod +x /usr/local/lib/hubzero/*.sh; \
    for f in /usr/local/lib/hubzero/hub-*.sh; do \
        ln -sf "$f" "/usr/local/bin/$(basename "$f" .sh)"; \
    done; \
    mkdir -p "$COMPOSER_HOME"; \
    chown -R www-data:www-data "$COMPOSER_HOME"

# Every repo in this container (the CMS, plus each extension under app/) is
# owned by www-data while maintenance often runs as root, which trips git's
# dubious-ownership guard. Single-tenant container, so a blanket exemption is
# the pragmatic call -- without it `git` in a template just refuses to run.
RUN git config --system --add safe.directory '*'

WORKDIR /var/www/html
EXPOSE 80 443

HEALTHCHECK --interval=30s --timeout=5s --start-period=180s --retries=5 \
    CMD curl -fsS -o /dev/null http://127.0.0.1/ || exit 1

ENTRYPOINT ["/usr/local/lib/hubzero/entrypoint.sh"]
CMD ["apache2-foreground"]
