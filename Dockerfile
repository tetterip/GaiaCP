# GaiaCP in a container, to try it on your own machine: docker compose up (docs/docker.md).
# Production on shared hosting uses the release zip instead (docs/installation.md).
FROM php:8.3-apache

# The PHP extensions GaiaCP needs (System health → Required extensions) and the recommended zip and gd;
# ctype, curl, dom, fileinfo, filter, mbstring, openssl, pdo_sqlite, session, simplexml, tokenizer and xml are built in.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl zip gd pdo_mysql bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/gaiacp.ini
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
RUN a2enmod rewrite headers

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# The libraries first, so a code change does not download them again
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader

COPY . .
# .env lives in the storage volume. The link belongs to www-data: /var/www/html is world-writable with the sticky
# bit, and where the kernel protects symlinks (fs.protected_symlinks, on in Ubuntu) www-data can't follow root's link
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f .env && ln -s storage/.env .env && chown -h www-data:www-data .env \
    && chown -R www-data:www-data storage bootstrap/cache plugins lang

COPY docker/entrypoint.sh /usr/local/bin/gaiacp-entrypoint
RUN chmod +x /usr/local/bin/gaiacp-entrypoint

VOLUME /var/www/html/storage
EXPOSE 80
ENTRYPOINT ["gaiacp-entrypoint"]
CMD ["apache2-foreground"]
