# Use official PHP 8.4 Apache image
FROM php:8.4-apache

# Install system dependencies
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        zip \
        unzip \
        wget; \
    rm -rf /var/lib/apt/lists/*

# Install APCu for caching
ENV APCU_VERSION=5.1.26
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install apcu-$APCU_VERSION; \
    docker-php-ext-enable apcu; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mysqli zip; \
    rm -rf /tmp/pear; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN set -eux; \
    a2enmod rewrite; \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf

# -----------------------------------------------------------
# FIX: Write the correct Apache config directly
# -----------------------------------------------------------
RUN echo '<VirtualHost *:80>\n\
    ServerAdmin webmaster@localhost\n\
    DocumentRoot /var/www/html/src\n\
    \n\
    <Directory /var/www/html/src>\n\
        Options -Indexes +FollowSymLinks\n\
        AllowOverride All\n\
        Require all granted\n\
    </Directory>\n\
    \n\
    ErrorLog ${APACHE_LOG_DIR}/error.log\n\
    CustomLog ${APACHE_LOG_DIR}/access.log combined\n\
</VirtualHost>' > /etc/apache2/sites-available/000-default.conf

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# Configure PHP settings
RUN set -eux; \
    { \
        echo "memory_limit = 512M"; \
        echo "max_execution_time = 600"; \
        echo "max_input_time = 600"; \
        echo "post_max_size = 128M"; \
        echo "upload_max_filesize = 128M"; \
        echo "max_input_vars = 5000"; \
        echo "apc.enable_cli = 1"; \
    } > /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Copy composer files
COPY composer.json ./

# Install PHP dependencies
RUN composer install --no-interaction --no-progress --prefer-dist

# Copy application files
COPY ./src ./src
COPY .env ./

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html

# Expose port 80
EXPOSE 80
