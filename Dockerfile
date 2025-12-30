# Official PHP Apache image (PHP 8.4 + Apache)
FROM php:8.4-apache

# ----------------------------
# System dependencies
# ----------------------------
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libzip-dev \
        zip \
        unzip \
        wget \
    ; \
    rm -rf /var/lib/apt/lists/*

# ----------------------------
# PHP extensions
# ----------------------------
ENV APCU_VERSION=5.1.26

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    pecl install apcu-${APCU_VERSION}; \
    docker-php-ext-enable apcu; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo \
        pdo_mysql \
        mysqli \
        zip; \
    rm -rf /tmp/pear; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

# ----------------------------
# Apache config
# ----------------------------
RUN set -eux; \
    a2enmod rewrite; \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf; \
    # Change DocumentRoot to point directly to api folder if that is your entry point
    # or keep it at html if you access via localhost/api/
    sed -ri -e 's!/var/www/html!/var/www/html!g' /etc/apache2/sites-available/*.conf; \
    { \
      echo "<Directory /var/www/html>"; \
      echo "    AllowOverride All"; \
      echo "</Directory>"; \
    } >> /etc/apache2/apache2.conf

# ----------------------------
# Composer
# ----------------------------
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1

# ----------------------------
# PHP Runtime Settings
# ----------------------------
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


    
# ----------------------------
# App Code & Dependencies
# ----------------------------
WORKDIR /var/www/html

COPY composer.json ./

# Install dependencies (Creates /var/www/html/vendor)
RUN composer install --no-interaction --no-progress --prefer-dist

# --- CHANGED: Copy to ./src/ instead of ./ ---
COPY src/ ./src/

COPY .env ./

# --- ADDED: Update Apache DocumentRoot to point to src/api ---
# This ensures http://localhost/ loads src/api/index.php
RUN sed -ri -e 's!/var/www/html!/var/www/html/src/api!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!/var/www/html/src/api!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80