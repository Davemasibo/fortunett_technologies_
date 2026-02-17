# Stage 1: Build dependencies
FROM php:8.2-apache AS builder

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock* ./

# Install PHP dependencies
RUN composer install --no-dev --no-interaction --prefer-dist

# Stage 2: Runtime
FROM php:8.2-apache

# Install runtime dependencies only
RUN apt-get update && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install mysqli pdo pdo_mysql

# Enable Apache modules
RUN a2enmod rewrite && a2enmod headers

# Optimize Apache performance
RUN sed -i 's/MaxRequestWorkers 256/MaxRequestWorkers 512/' /etc/apache2/mods-available/mpm_prefork.conf

# Set working directory
WORKDIR /var/www/html

# Copy built dependencies from builder
COPY --from=builder /app/vendor ./vendor

# Copy application code
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/cache /var/www/html/logs 2>/dev/null || true

# Create necessary directories
RUN mkdir -p /var/www/html/cache /var/www/html/logs && \
    chown -R www-data:www-data /var/www/html/cache /var/www/html/logs

# Set Apache DocumentRoot
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf

# Copy Apache config
COPY .docker/apache.conf /etc/apache2/conf-available/app.conf
RUN a2enconf app

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -f http://localhost/index.php || exit 1

EXPOSE 80

CMD ["apache2-foreground"]
