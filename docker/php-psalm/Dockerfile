FROM php:8.2-cli

RUN docker-php-ext-install pdo_mysql

RUN apt update
RUN apt install -y git unzip

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
