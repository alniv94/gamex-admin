#!/bin/sh
set -eu
cd /var/www/html
if [ ! -f .env ]; then
    echo "Create the private .env from .env.example before starting the admin app." >&2
    exit 1
fi
composer_signature="$(sha256sum composer.json composer.lock | sha256sum | cut -d ' ' -f 1)"
if [ ! -f vendor/.admin-signature ] || [ "$(cat vendor/.admin-signature)" != "$composer_signature" ]; then
    composer install --no-interaction --prefer-dist
    printf '%s' "$composer_signature" > vendor/.admin-signature
fi
npm_signature="$(sha256sum package.json package-lock.json | sha256sum | cut -d ' ' -f 1)"
if [ ! -f node_modules/.admin-signature ] || [ "$(cat node_modules/.admin-signature)" != "$npm_signature" ]; then
    npm ci
    printf '%s' "$npm_signature" > node_modules/.admin-signature
fi
mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
if [ "$(id -u)" = 0 ]; then
    chown -R www-data:www-data storage bootstrap/cache
fi
# Schema changes, account imports, and application-key creation are explicit operator actions.
exec "$@"
