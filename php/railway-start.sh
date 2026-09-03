#!/bin/sh
# Railway-only start command. Baked into the `prod` stage of php/Dockerfile and
# invoked exclusively via `railway.json` deploy.startCommand — never by the image
# CMD and never by `docker compose`. Safe to assume: the `prod` image on Railway,
# real runtime env vars (APP_ENV=prod, APP_SECRET, DATABASE_URL) present.
set -e

# Railway injects a dynamic $PORT and routes to whatever port the container
# listens on. The Dockerfile hardcodes 80 in both ports.conf and the vhost.
PORT="${PORT:-80}"

sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Warmup with real env vars present (pre-deploy runs in a throwaway container
# whose filesystem never reaches runtime, so it can't do this).
php bin/console assets:install public --no-interaction
php bin/console cache:clear --no-interaction

# var/ is written by www-data at runtime; the cache:clear above ran as root.
chown -R www-data:www-data var

exec apache2-foreground
