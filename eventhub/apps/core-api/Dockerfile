FROM php:8.2-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    curl \
    libpq-dev \
    zip \
    unzip \
    git \
    linux-headers

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql opcache sockets

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy dependency files first for Docker layer caching
COPY composer.json composer.lock* ./

# Install PHP dependencies
RUN composer config audit.abandoned ignore || true
RUN composer config policy.advisories.block false || true
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy application code
COPY . .

# Set permissions and create missing directories
RUN mkdir -p /var/www/html/bootstrap/cache \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 755 /var/www/html/storage

# Install Artisan serve dependencies and start
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
