# One image, three roles (APP_ROLE): web (nginx + php-fpm), worker (queue),
# scheduler (schedule:work). Render runs one service per role from it.

# ---- PHP dependencies ----------------------------------------------------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---- runtime -------------------------------------------------------------
FROM php:8.3-fpm-alpine3.20

# nginx + supervisor for the web role; ffmpeg for gallery / wish videos.
RUN apk add --no-cache nginx supervisor ffmpeg icu-libs libzip libpng libjpeg-turbo libwebp freetype \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql bcmath gd intl zip exif opcache pcntl \
    && apk del .build

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/fpm.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf
COPY docker/start.sh /usr/local/bin/start

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

RUN chmod +x /usr/local/bin/start \
    && mkdir -p storage/framework/cache storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    FFMPEG_PATH=/usr/bin/ffmpeg \
    PORT=8080 \
    RUN_QUEUE=false \
    RUN_SCHEDULER=false

EXPOSE 8080
CMD ["start"]
