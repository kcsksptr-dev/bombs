FROM alpine:3.24 AS base
LABEL maintainer="BIG FISH <cafe@bigfish.hu>"

ARG PHP_VERSION=85
ARG WORKDIR=/var/www/app

ENV PHP_VERSION=${PHP_VERSION}
ENV TZ=Europe/Budapest

RUN apk add --update --no-cache \
    curl \
    git \
    icu-data-full \
    make \
    nginx \
    nodejs \
    npm \
    supervisor \
    tzdata \
    unzip \
    php${PHP_VERSION} \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-common \
    php${PHP_VERSION}-ctype \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-dom \
    php${PHP_VERSION}-fileinfo \
    php${PHP_VERSION}-iconv \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-openssl \
    php${PHP_VERSION}-pcntl \
    php${PHP_VERSION}-pdo \
    php${PHP_VERSION}-pdo_pgsql \
    php${PHP_VERSION}-pdo_sqlite \
    php${PHP_VERSION}-pecl-redis \
    php${PHP_VERSION}-phar \
    php${PHP_VERSION}-posix \
    php${PHP_VERSION}-session \
    php${PHP_VERSION}-simplexml \
    php${PHP_VERSION}-sockets \
    php${PHP_VERSION}-sodium \
    php${PHP_VERSION}-tokenizer \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-xmlreader \
    php${PHP_VERSION}-xmlwriter \
    php${PHP_VERSION}-zip \
  && rm -rf /var/cache/apk/* \
  && curl -o /usr/local/bin/composer https://getcomposer.org/composer-stable.phar \
  && chmod ugo+rx /usr/local/bin/composer \
  # Set timezone (https://wiki.alpinelinux.org/wiki/Setting_the_timezone)
  && cp -f /usr/share/zoneinfo/${TZ} /etc/localtime \
  && echo "${TZ}" > /etc/timezone \
  && chmod ugo+r -R /usr/share/zoneinfo \
  && chmod ugo+rw /etc/localtime \
  && chmod ugo+rw /etc/timezone \
  # Create user
  && (getent group www-data || addgroup -S www-data) \
  && (getent passwd www-data || adduser -S www-data -G www-data) \
  # Create document root
  && mkdir -p ${WORKDIR} \
  && chown -R www-data:www-data ${WORKDIR} \
  # Set up PHP
  && ln -sf /usr/bin/php${PHP_VERSION} /usr/bin/php \
  && ln -sf /usr/sbin/php-fpm${PHP_VERSION} /usr/sbin/php-fpm \
  && ln -sf /dev/stdout /var/log/php_error.log \
  && chown -R www-data:www-data /var/log/php_error.log \
  # Set up NGINX
  && chmod +x /var/lib/nginx -R \
  && mkdir -p /var/tmp/nginx \
  && chown -R www-data:www-data /var/tmp/nginx

WORKDIR "${WORKDIR}"

COPY .docker/src/entrypoint.sh /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]

FROM base AS local

ARG UID=1000
ARG GID=1000

# Local dev bind-mounts the project: run www-data with the host user's UID/GID so files written by
# php-fpm, Reverb, the queue worker and `make` targets are all owned by the developer
RUN apk add --no-cache shadow \
  && groupmod -o -g ${GID} www-data \
  && usermod -o -u ${UID} -g ${GID} -d /home/www-data www-data \
  && mkdir -p /home/www-data \
  && chown -R www-data:www-data /home/www-data /var/tmp/nginx /var/lib/nginx \
  && rm -rf /var/cache/apk/*
