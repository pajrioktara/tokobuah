FROM php:7.3-apache

RUN apt-get update && apt-get install -y libpq-dev && docker-php-ext-install pdo mysqli pdo_mysql
RUN a2enmod rewrite
WORKDIR /var/www/html/tokobuah
COPY --chown=www-data:www-data . /var/www/html/tokobuah
EXPOSE 80

