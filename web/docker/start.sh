#!/bin/sh
# Container entry point. One image, several roles:
#   start web            Apache serving the app (default)
#   start worker         queue worker (sends queued emails)
#   start housekeeping   one run of loft:housekeeping (reminders, expiry), for an hourly cron
#   start migrate        run database migrations (Render's pre-deploy command)
set -e
cd /var/www/html

# Render's generateValue makes a base64 key without Laravel's "base64:" prefix.
case "${APP_KEY:-}" in
    ''|base64:*) ;;
    *) export APP_KEY="base64:${APP_KEY}" ;;
esac
# On a Render web service, fall back to its public URL when APP_URL isn't set.
if [ -z "${APP_URL:-}" ] && [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi
export PORT="${PORT:-8080}"

# Cache config, routes and views against this container's environment.
php artisan optimize --no-interaction
chown -R www-data:www-data storage bootstrap/cache

role="${1:-web}"
[ $# -gt 0 ] && shift
case "$role" in
    web)          exec apache2-foreground ;;
    worker)       exec su -s /bin/sh www-data -c 'php artisan queue:work --tries=3 --backoff=30 --max-time=3600' ;;
    housekeeping) exec su -s /bin/sh www-data -c 'php artisan loft:housekeeping' ;;
    migrate)      exec php artisan migrate --force --no-interaction ;;
    *)            exec "$role" "$@" ;;
esac
