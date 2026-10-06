# syntax=docker/dockerfile:1
#
# Crimeify for a cloud host: Apache and PHP with the SQL Server driver (pdo_sqlsrv and Microsoft's ODBC driver 18).
# One image runs the same on Azure App Service for Containers, a VPS or your own computer. See DEPLOY.md.
#
#   docker build -t crimeify .
#   az acr build -r <registry> -t crimeify:latest .      # builds in Azure, so no Docker is needed here
#
# composer.lock needs PHP 8.4.1 or newer. The SQL Server driver is pinned to the version used on the development
# computer; if a build says that version isn't on PECL, give --build-arg SQLSRV_VERSION=<the newest at
# https://pecl.php.net/package/sqlsrv>.
ARG PHP_VERSION=8.4

# Debian 12 (bookworm) is pinned because Microsoft publishes the ODBC driver for it.
FROM php:${PHP_VERSION}-apache-bookworm

ARG SQLSRV_VERSION=5.13.3

# The SQL Server driver, then the PHP extensions the app uses: gd (profile photos), zip (Excel files), intl, opcache.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl gnupg ca-certificates git unzip \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev unixodbc-dev \
    && curl -fsSL https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor -o /usr/share/keyrings/microsoft-prod.gpg \
    && curl -fsSL https://packages.microsoft.com/config/debian/12/prod.list -o /etc/apt/sources.list.d/mssql-release.list \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd zip intl opcache \
    && pecl install "sqlsrv-${SQLSRV_VERSION}" "pdo_sqlsrv-${SQLSRV_VERSION}" \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# PHP's production settings, with this app's few changes on top (docker/php.ini), and Apache serving public/.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-crimeify.ini"
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /var/www/html

# The packages first, so a change to the code alone doesn't download them again.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
# No scripts: they would start the app, which needs a database. Laravel finds the packages on first start instead.
RUN composer dump-autoload --no-dev --optimize --no-scripts \
    && rm -f /usr/bin/composer

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
    && chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
