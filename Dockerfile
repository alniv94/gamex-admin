FROM node:24-bookworm-slim AS node
FROM php:8.5-apache

ENV COMPOSER_ALLOW_SUPERUSER=1 APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN apt-get update && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip bcmath mbstring curl \
    && a2enmod rewrite headers \
    && sed -ri 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf \
    && rm -rf /var/lib/apt/lists/*
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock package.json package-lock.json .npmrc ./
RUN composer install --no-interaction --prefer-dist --no-scripts --no-autoloader && npm ci
COPY . .
RUN composer dump-autoload --no-scripts && php artisan package:discover --ansi \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/entrypoint.sh /usr/local/bin/admin-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/admin-entrypoint && chmod +x /usr/local/bin/admin-entrypoint
ENTRYPOINT ["admin-entrypoint"]
CMD ["apache2-foreground"]
