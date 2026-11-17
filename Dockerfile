FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    linux-headers

# Install PHP extensions
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        zip \
        gd \
        bcmath \
        intl \
        mbstring \
        opcache

# Security hardening: custom php.ini
COPY php.ini /usr/local/etc/php/conf.d/security.ini

# Working directory and non-root execution
WORKDIR /var/www/html
RUN mkdir -p /var/nirf/storage && chown -R www-data:www-data /var/nirf/storage

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
