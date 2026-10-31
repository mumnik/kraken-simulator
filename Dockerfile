FROM php:8.4.27-fpm-alpine

COPY zz-listen.conf /usr/local/etc/php-fpm.d/zz-listen.conf

# No gd/pdo_pgsql — this service does no image work and has no real
# database (sqlite, bundled with the base image, is enough).
RUN apk add --no-cache libzip-dev unzip linux-headers oniguruma-dev \
    && docker-php-ext-install pdo bcmath pcntl zip opcache sockets mbstring

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{sessions,views,cache} storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
