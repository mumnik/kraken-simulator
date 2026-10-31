#!/bin/sh
set -e

# Ensure storage dirs exist and are writable (named volume mounts as root
# initially) — includes storage/database.sqlite itself (see .env.example's
# DB_DATABASE for why the sqlite file lives under storage/, not the
# Laravel-default database/).
mkdir -p /var/www/html/storage/framework/sessions /var/www/html/storage/framework/views /var/www/html/storage/framework/cache /var/www/html/storage/logs
touch /var/www/html/storage/database.sqlite
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

exec "$@"
