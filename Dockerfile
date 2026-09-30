FROM php:8.2-apache

# Enable Apache modules
RUN a2enmod rewrite

# Explicitly configure Apache to allow .htaccess overrides in /var/www/html
RUN echo '<Directory /var/www/html/>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/override.conf \
    && a2enconf override
