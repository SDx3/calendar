FROM php:8-apache

ENV COMPOSER_ALLOW_SUPERUSER=1 DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y zip git libzip-dev && docker-php-ext-install zip
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN a2enmod rewrite negotiation

RUN sed -i $'s|ServerAdmin webmaster@localhost|ServerAdmin webmaster@localhost\\nAllowOverride All|' /etc/apache2/sites-available/000-default.conf

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY . /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
