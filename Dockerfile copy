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
# PHP extensions: ALL IN ONE (APCu + GD + DB + ZIP)
# Install build dependencies ONCE, install all extensions, then purge
# ----------------------------
ENV APCU_VERSION=5.1.26

RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS; \
    # Install APCu from PECL
    pecl install apcu-${APCU_VERSION}; \
    docker-php-ext-enable apcu; \
    # Configure and install GD + other extensions
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" \
        gd \
        pdo \
        pdo_mysql \
        mysqli \
        zip; \
    # Clean up PECL temp files and build dependencies
    rm -rf /tmp/pear; \
    apt-get purge -y --auto-remove $PHPIZE_DEPS; \
    rm -rf /var/lib/apt/lists/*

# ----------------------------
# Apache config
# ----------------------------
RUN set -eux; \
    a2enmod rewrite; \
    echo "ServerName localhost" >> /etc/apache2/apache2.conf; \
    { \
      echo "<Directory /var/www/html>"; \
      echo "    AllowOverride All"; \
      echo "</Directory>"; \
    } >> /etc/apache2/apache2.conf

# ----------------------------
# Composer (from official composer image)
# ----------------------------
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1

# ----------------------------
# PHP runtime settings
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
# App code
# ----------------------------
WORKDIR /var/www/html

COPY --chown=www-data:www-data src/ /var/www/html/

# Optional: install composer deps if composer.json exists
RUN set -eux; \
    if [ -f composer.json ]; then \
        composer install --no-interaction --no-progress --prefer-dist; \
    fi; \
    chown -R www-data:www-data /var/www/html; \
    chmod -R 755 /var/www/html

EXPOSE 80
