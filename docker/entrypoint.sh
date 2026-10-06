#!/bin/sh
# Runs each time the container starts, then hands over to Apache (the CMD).
set -eu

cd /var/www/html

if [ -z "${APP_KEY:-}" ]; then
    echo "APP_KEY is not set. Make one with: php artisan key:generate --show" >&2
    exit 1
fi

mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache

# Creates the tables that are missing, like logins (dbo.sessions), and runs any new migration. Off unless asked for,
# because with more than one copy of the app starting at once, two could migrate at the same time, and the database
# user needs permission to create tables. The first time, and after each release that adds a migration, either set
# RUN_MIGRATIONS=true for one start, or run `php artisan migrate --force` yourself (DEPLOY.md, step 7).
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

# Read the settings and compile the pages once now, not on the first visitor's request. Not route:cache: the Routes
# page adds its routes from dbo.AppRoutes on every request, and caching routes would hide a change made there.
php artisan config:cache
php artisan view:cache

# The commands above ran as root; Apache runs as www-data and writes logs, caches and uploads waiting for review here.
chown -R www-data:www-data storage bootstrap/cache

exec "$@"
