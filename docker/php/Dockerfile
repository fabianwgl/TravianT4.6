FROM php:8.3.33-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        mariadb-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" bcmath gd mysqli pcntl pdo_mysql zip \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && a2enmod headers rewrite expires \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php/openvillage.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/openvillage.ini
COPY . /app

RUN mkdir -p \
        /app/main_script/copyable/include/runtime/backups \
    && chown -R www-data:www-data \
        /app/main_script/copyable/include/runtime

WORKDIR /app
