FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        oniguruma-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
        imagemagick \
        imagemagick-libs \
    && docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
    && docker-php-ext-install \
        zip \
        mbstring \
        opcache \
        gd \
    # ext-imagick: powers the series cover ordered-Bayer dither pipeline.
    # Built via pecl (no docker-php-ext-install recipe). Build deps are
    # dropped after compile to keep the image small.
    && apk add --no-cache --virtual .imagick-build ${PHPIZE_DEPS} imagemagick-dev \
    && pecl install imagick \
    && docker-php-ext-enable imagick \
    && apk del .imagick-build \
    && curl -sS https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
