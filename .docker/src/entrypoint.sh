#!/bin/sh

set -eo pipefail

if [ ! -z "${TZ}" ]; then
    echo -e "\nInitialize timezone\n"
    cat /usr/share/zoneinfo/"${TZ}" >/etc/localtime
    echo "${TZ}" >/etc/timezone
fi

if [ ! -z "$LARAVEL_INIT_ENABLED" ] && [ "$LARAVEL_INIT_ENABLED" == "1" ]; then
    echo -e "\nInitialize Laravel\n"
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
    php artisan view:cache
fi

if [ ! -z "$LARAVEL_DB_MIGRATION_ENABLED" ] && [ "$LARAVEL_DB_MIGRATION_ENABLED" == "1" ]; then
    echo -e "\nRun database migration\n"
    php artisan migrate --force
fi

if [ ! -z "$LARAVEL_DB_SEEDER_ENABLED" ] && [ "$LARAVEL_DB_SEEDER_ENABLED" == "1" ]; then
    echo -e "\nRun database seeder\n"
    php artisan db:seed --force
fi

if [ ! -z "$PORT" ]; then
    echo -e "\nSet NGINX listen port (${PORT})\n"
    sed -i "s/80/${PORT}/g" /etc/nginx/conf.d/default.conf
fi

exec "$@"
