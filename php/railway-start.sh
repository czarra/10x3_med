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

# Enforce a single MPM at container start — cache-proof. Railway's builder keeps
# serving a stale `base` layer, so the Dockerfile's own MPM cleanup can be
# bypassed; "AH00534: More than one MPM loaded" aborts Apache before it binds.
# Drop every enabled MPM, then relink only prefork (mod_php needs it).
rm -f /etc/apache2/mods-enabled/mpm_*.load /etc/apache2/mods-enabled/mpm_*.conf
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/mpm_prefork.load
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/mpm_prefork.conf

# Warmup with real env vars present (pre-deploy runs in a throwaway container
# whose filesystem never reaches runtime, so it can't do this).
php bin/console assets:install public --no-interaction
php bin/console cache:clear --no-interaction

# var/ is written by www-data at runtime; the cache:clear above ran as root.
chown -R www-data:www-data var

exec apache2-foreground
