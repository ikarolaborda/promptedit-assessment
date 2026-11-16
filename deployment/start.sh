#!/bin/sh
set -eu
php artisan config:cache
php artisan migrate --force
php artisan studio:demo-user
php artisan view:cache
exec supervisord -c /etc/supervisor/conf.d/studio.conf
