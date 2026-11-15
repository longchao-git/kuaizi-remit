# 本地开发镜像：Laravel 13 需要 PHP >= 8.3（见 composer.json 的 platform 配置）。
FROM php:8.3-cli-alpine

# pdo_mysql：连接 sas-mysql；bcmath：金额/汇率计算；
# intl / zip：Laravel 与 Composer 常用扩展；pcntl / opcache：常驻命令与性能。
RUN apk add --no-cache $PHPIZE_DEPS bash git curl icu-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath intl zip pcntl opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /var/www/html

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8688

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8688"]
