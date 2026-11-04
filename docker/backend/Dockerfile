FROM composer:2.11.1@sha256:537fb03057ed901ae06bde7ef25af348903485ae1acc7fc789b80d8bac666f35 AS composer

FROM php:8.4.27-fpm-bookworm@sha256:1b6bb4f3d32dfbcd1e159d7bd47caa8852507f61666db7731d73f34acec781b6 AS runtime-base
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev librabbitmq-dev libfcgi-bin gosu libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libxml2-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd \
    && for extension in pdo_pgsql pcntl opcache zip mbstring dom simplexml xml xmlreader xmlwriter; do \
        if ! php -r "exit(extension_loaded('$extension') ? 0 : 1);"; then docker-php-ext-install -j"$(nproc)" "$extension"; fi; \
    done \
    && pecl install amqp-2.2.0 \
    && docker-php-ext-enable amqp \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
WORKDIR /var/www/app

FROM runtime-base AS dependencies
ENV COMPOSER_IPRESOLVE=4 COMPOSER_MAX_PARALLEL_HTTP=4 COMPOSER_CACHE_DIR=/tmp/composer-cache
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY backend/composer.json backend/composer.lock ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --no-autoloader
COPY backend/src ./src
COPY backend/migrations ./migrations
RUN composer dump-autoload --no-dev --classmap-authoritative --no-scripts \
    && composer check-platform-reqs --no-dev

FROM dependencies AS testing
COPY backend/ ./
RUN --mount=type=cache,target=/tmp/composer-cache \
    composer install --no-scripts --no-interaction --prefer-dist --no-progress \
    && composer check-platform-reqs
CMD ["composer", "test"]

FROM runtime-base AS production
COPY --from=dependencies --chown=www-data:www-data /var/www/app/vendor ./vendor
COPY --chown=www-data:www-data backend/composer.json backend/composer.lock ./
COPY --chown=www-data:www-data backend/public ./public
COPY --chown=www-data:www-data backend/src ./src
COPY --chown=www-data:www-data backend/bin ./bin
COPY --chown=www-data:www-data backend/migrations ./migrations
COPY --chown=www-data:www-data backend/fixtures ./fixtures
COPY docker/backend/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/backend/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY --chmod=755 docker/backend/entrypoint.sh /usr/local/bin/app-entrypoint
RUN mkdir -p /var/app/uploads /var/app/images \
    && chown -R www-data:www-data /var/app
EXPOSE 9000
ENTRYPOINT ["app-entrypoint"]
STOPSIGNAL SIGQUIT
CMD ["php-fpm", "-F"]
