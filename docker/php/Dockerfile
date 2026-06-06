FROM php:8.3-fpm-alpine

RUN apk add --no-cache git curl libzip-dev zip unzip icu-dev oniguruma-dev nodejs npm

RUN docker-php-ext-install pdo pdo_mysql zip intl opcache mbstring
RUN pecl install redis && docker-php-ext-enable redis

COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
EXPOSE 9000
