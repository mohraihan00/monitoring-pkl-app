#!/bin/sh
set -e

cd /var/www/html

# Configure Nginx to listen on Render's $PORT (defaults to 10000 on Render)
LISTEN_PORT="${PORT:-10000}"
sed "s/__PORT__/${LISTEN_PORT}/g" /etc/nginx/templates/default.conf.template > /etc/nginx/http.d/default.conf

# Ensure storage and cache directories exist and are writable
mkdir -p storage/framework/sessions \
         storage/framework/views \
         storage/framework/cache/data \
         storage/logs \
         storage/app/public \
         bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Create .env from .env.example if not present
if [ ! -f .env ]; then
    cp .env.example .env
fi

# Generate fallback APP_KEY if not provided via environment variable
if [ -z "$APP_KEY" ]; then
    php artisan key:generate --force --no-interaction
fi

# Create public storage symlink
php artisan storage:link --force || true

# Clear stale Laravel caches before booting
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Run migrations & initial seed automatically if database is configured
if [ -n "$DB_HOST" ] || [ -n "$DATABASE_URL" ] || [ "$DB_CONNECTION" = "sqlite" ]; then
    php artisan migrate --force --no-interaction || echo "Warning: migration failed or database not reachable yet"
    USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" 2>/dev/null | tr -d '\r\n' || echo "1")
    if [ "$USER_COUNT" = "0" ]; then
        php artisan db:seed --force --no-interaction || true
    fi
fi

# Start PHP-FPM in background and Nginx in foreground
php-fpm -D
exec nginx -g "daemon off;"
