FROM php:8.2-apache

# Enable Apache rewrite module for .htaccess support
RUN a2enmod rewrite

# Copy your local project files into Apache's web root
COPY . /var/www/html/

# Set proper permissions
RUN chown -R www-data:www-data /var/www/html
