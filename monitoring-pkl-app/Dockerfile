FROM php:8.3-fpm-alpine

# Install system packages, Nginx, and required PHP extensions (PostgreSQL, GD, Zip, BCMath, Opcache)
RUN apk add --no-cache \
    nginx \
    bash \
    curl \
    git \
    unzip \
    libpq-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo \
        pdo_pgsql \
        pgsql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        intl \
        opcache

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Install PHP dependencies first for layer caching
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# Copy application source
COPY . /var/www/html

# Complete Composer autoloader
RUN composer dump-autoload --optimize --no-dev

# Configure Nginx & entrypoint
RUN mkdir -p /etc/nginx/templates /etc/nginx/http.d /run/nginx \
    && cp /var/www/html/docker/nginx.conf /etc/nginx/templates/default.conf.template \
    && cp /var/www/html/docker/entrypoint.sh /usr/local/bin/docker-entrypoint.sh \
    && sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && sed -i 's/\r$//' /etc/nginx/templates/default.conf.template \
    && chmod +x /usr/local/bin/docker-entrypoint.sh \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

EXPOSE 10000

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
