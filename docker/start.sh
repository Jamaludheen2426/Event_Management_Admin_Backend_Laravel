#!/bin/sh
# Container entry point. APP_ROLE picks what this container does:
#   web        nginx + php-fpm on $PORT (default)
#   worker     the queue (push, e-mail campaigns, translations...)
#   scheduler  scheduled work (due messages and notices, every minute)
set -e
cd /var/www/html

# Configuration is read from the environment once, at start.
php artisan config:cache
php artisan route:cache
php artisan event:cache

case "${APP_ROLE:-web}" in
  web)
    # Only the web service migrates, and only when asked (first deploy, cutover).
    if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
      php artisan migrate --force
    fi
    sed -i "s/listen 8080;/listen ${PORT:-8080};/" /etc/nginx/http.d/default.conf
    exec supervisord -c /etc/supervisord.conf
    ;;
  worker)
    exec php artisan queue:work --sleep=3 --tries=3 --max-time=3600
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    echo "Unknown APP_ROLE: ${APP_ROLE}" >&2
    exit 1
    ;;
esac
