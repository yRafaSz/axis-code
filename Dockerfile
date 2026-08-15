FROM php:8.3-apache

RUN set -eux; \
    php -m | grep -qx curl; \
    docker-php-ext-install pdo_mysql; \
    a2enmod rewrite headers; \
    sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
