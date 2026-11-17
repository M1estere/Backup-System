FROM php:8.4-cli

ARG UID=1000
ARG GID=1000

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip zip curl ca-certificates \
        openssh-client sshpass \
        libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev libxml2-dev \
        default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql zip intl bcmath gd pcntl exif mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupadd -g ${GID} app && useradd -u ${UID} -g ${GID} -m -s /bin/bash app \
    && mkdir -p /var/www/html && chown app:app /var/www/html

COPY php.ini /usr/local/etc/php/conf.d/zz-app.ini

USER app
WORKDIR /var/www/html
