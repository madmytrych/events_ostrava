FROM php:8.3-fpm-alpine

# System deps
RUN apk add --no-cache \
    bash curl git unzip icu-dev oniguruma-dev \
    libzip-dev zlib-dev \
    mysql-client \
    linux-headers \
    tzdata \
    $PHPIZE_DEPS

# PHP extensions
RUN docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) \
    pdo_mysql mbstring intl zip opcache

# Xdebug
RUN pecl install xdebug \
 && docker-php-ext-enable xdebug

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# php.ini overrides
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zzz-custom.ini

WORKDIR /var/www/html

# Create a non-root user (optional, but nice)
RUN addgroup -g 1000 app && adduser -G app -g app -s /bin/sh -D -u 1000 app
USER app
