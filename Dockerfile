# syntax=docker/dockerfile:1.7

# keep readable release tags while immutable digests make every build auditable
ARG PHP_IMAGE=php:8.4.25-fpm-alpine3.24@sha256:60ad95af451cc1b0e435ece74816798143658c1b615c1b7ec09f01fed73fca08
ARG COMPOSER_IMAGE=composer:2.10.3@sha256:4d045ea9f71d5d111a95e608400da61d187e487adf9eaf2dfe068998a8d4f584
ARG NODE_IMAGE=node:24.20.0-alpine3.24@sha256:e67514e5d0f6c46656005e1b693b2ec9d52e80b641307de684d4a015ba7a4eaf

# stage 0: share the PHP extension platform between dependencies and runtime
FROM ${PHP_IMAGE} AS php-base

# compile the extensions used by Laravel and production dependencies once
RUN apk add --no-cache \
        icu-libs \
        libpq \
        libzip \
        oniguruma \
    && apk add --no-cache --virtual .build-dependencies \
        $PHPIZE_DEPS \
        icu-dev \
        libpq-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl \
        pdo_pgsql \
        zip \
    && apk del .build-dependencies \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# stage 1: install deterministic production PHP dependencies
FROM ${COMPOSER_IMAGE} AS composer-bin
FROM php-base AS vendor

# isolate dependency installation from the application runtime filesystem
WORKDIR /app
COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer

# cache dependencies independently of application source changes
COPY composer.json composer.lock ./

# defer autoload generation until application classes are present
RUN --mount=type=cache,target=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_CACHE_DIR=/tmp/composer-cache composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader \
        --prefer-dist

# stage 2: compile deterministic frontend assets
FROM ${NODE_IMAGE} AS frontend

# keep frontend tooling in a disposable build stage
WORKDIR /app

# install the dependency graph recorded by the frontend lockfile
COPY package.json package-lock.json ./

RUN --mount=type=cache,target=/root/.npm \
    npm ci --ignore-scripts --no-audit --no-fund

COPY resources ./resources
COPY vite.config.js ./
# include Flux components in Tailwind discovery
COPY --from=vendor /app/vendor ./vendor

# emit production assets for the runtime image
RUN npm run build

# stage 3: assemble the rootless production runtime
FROM php-base AS runtime

# use the web and process managers supplied by the pinned Alpine branch
RUN apk add --no-cache \
        nginx \
        supervisor

WORKDIR /var/www/html

# retain root ownership of application source and immutable build outputs
COPY --chown=root:root . .
COPY --from=vendor --chown=root:root /app/vendor ./vendor
COPY --from=frontend --chown=root:root /app/public/build ./public/build

# install the versioned web, PHP, and process-manager configuration
COPY docker/production/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/production/nginx/default.conf /etc/nginx/http.d/default.conf
# replace the inherited root-oriented pool instead of layering over its directives
COPY docker/production/php-fpm/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/production/php-fpm/production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/production/php-fpm/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/production/supervisor/supervisord.conf /etc/supervisord.conf
COPY docker/production/supervisor/conf.d/ /etc/supervisor/conf.d/

# prepare writable storage, cache, sockets, logs, and request-buffer directories
RUN --mount=from=composer-bin,source=/usr/bin/composer,target=/usr/local/bin/composer,ro \
    mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        /run/php-fpm \
        /var/lib/nginx/logs \
        /var/lib/nginx/tmp/client_body \
        /var/lib/nginx/tmp/fastcgi \
        /var/lib/nginx/tmp/proxy \
        /var/lib/nginx/tmp/scgi \
        /var/lib/nginx/tmp/uwsgi \
    && ln -sfn ../storage/app/public public/storage \
    && COMPOSER_ALLOW_SUPERUSER=1 composer dump-autoload \
        --no-dev --no-interaction --no-scripts --optimize --classmap-authoritative \
    && php artisan package:discover --no-ansi \
    && COMPOSER_ALLOW_SUPERUSER=1 composer check-platform-reqs --no-dev \
    && php -r 'require "vendor/autoload.php"; exit(class_exists("App\\Providers\\AppServiceProvider") && class_exists("Faker\\Factory") ? 0 : 1);' \
    && chown -R www-data:www-data \
        /run/php-fpm \
        /var/lib/nginx \
        bootstrap/cache \
        storage

# run the web server, PHP workers, and process manager as the runtime user
USER www-data

# validate service configuration under the actual runtime identity
RUN php-fpm -t && nginx -e /dev/stderr -t

# receive private HTTP traffic from the TLS-terminating deployment edge
EXPOSE 8080

# exercise the Nginx-to-PHP-to-Laravel request path
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget --quiet --output-document=- http://127.0.0.1:8080/up >/dev/null || exit 1

# cache injected runtime configuration, fail on startup errors, then hand PID 1 to Supervisor
ENTRYPOINT []
CMD ["/bin/sh", "-euc", ": \"${APP_KEY:?APP_KEY is required}\"; umask 077; php artisan optimize --no-ansi; exec supervisord -c /etc/supervisord.conf"]
