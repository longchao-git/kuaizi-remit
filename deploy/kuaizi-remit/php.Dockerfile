FROM php:8.3-fpm-alpine

RUN apk add --no-cache icu-libs libzip oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install -j2 pdo_mysql bcmath intl zip pcntl opcache mbstring \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV APP_ENV=production APP_DEBUG=false LOG_CHANNEL=stderr
WORKDIR /srv/app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --no-dev --no-scripts --optimize \
    && php artisan package:discover --ansi \
    && chmod -R a+rX /srv/app \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
CMD ["php-fpm", "-F"]
