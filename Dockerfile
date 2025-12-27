# Use the official PHP Apache image with latest version
FROM php:8.4-apache
# FROM mirror.gcr.io/library/php:8.4-apache

# Install system dependencies (add libzip-dev for ext-zip)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libzip-dev \
    zip \
    unzip \
    wget \
 && rm -rf /var/lib/apt/lists/*

# Install PHP extensions (include zip)
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd pdo pdo_mysql mysqli zip

# Enable Apache mod_rewrite
RUN a2enmod rewrite

##############################################
# Install Composer from official image
##############################################
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer
# COPY --from=mirror.gcr.io/library/composer:latest /usr/bin/composer /usr/local/bin/composer

# Set Composer to allow running as root
ENV COMPOSER_ALLOW_SUPERUSER=1

##############################################
# Set ServerName and permissions
##############################################
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Ensure Apache allows .htaccess overrides for specific directories
RUN echo '<Directory /var/www/html>' >> /etc/apache2/apache2.conf && \
    echo '    AllowOverride All' >> /etc/apache2/apache2.conf && \
    echo '</Directory>' >> /etc/apache2/apache2.conf

# Extended PHP configuration limits
RUN echo "memory_limit = 512M" > /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_input_time = 600" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "post_max_size = 128M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "upload_max_filesize = 128M" >> /usr/local/etc/php/conf.d/custom.ini && \
    echo "max_input_vars = 5000" >> /usr/local/etc/php/conf.d/custom.ini

# Set correct permissions for the web server user (after copying files)
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

##############################################
# COPY PHP application files from php_server to container
##############################################
COPY src/ /var/www/html/

##############################################
# Install Composer dependencies
##############################################
WORKDIR /var/www/html

# Set correct permissions for the web server user (after copying files)
RUN chown -R www-data:www-data /var/www/html
RUN chmod -R 755 /var/www/html

# Expose the container port
EXPOSE 80
