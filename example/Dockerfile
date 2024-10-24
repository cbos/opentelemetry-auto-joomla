# Pull in dependencies with composer
FROM composer:2.8.1 AS build
COPY example/composer.json ./
RUN composer install --ignore-platform-reqs

FROM joomla:4.4.9-php8.2-apache

ENV PHP_EXTENSION_INSTALLER 2.6.0

ADD https://github.com/mlocati/docker-php-extension-installer/releases/download/$PHP_EXTENSION_INSTALLER/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions \
  && install-php-extensions \
        zip \
        opentelemetry \
        protobuf \
        @composer

COPY example/otel.php.ini $PHP_INI_DIR/conf.d/.

## Copy in the composer vendor files and autoload.php
COPY --from=build /app/vendor /var/www/otel

# Copy during development
COPY src/JoomlaInstrumentation.php /var/www/otel/cbos/opentelemetry-auto-joomla/src/

WORKDIR /var/www/html

