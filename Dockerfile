# BookTrack public demo: PHP 8.3 + Apache, with the demo library in SQLite.
# Built and run by Render (see render.yaml and docker/README.md). The database is rebuilt
# with fresh demo data every time the container starts.
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev libonig-dev libsqlite3-dev libcurl4-openssl-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install gd mbstring pdo_mysql pdo_sqlite curl \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers expires \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

WORKDIR /var/www/html
COPY . ./
COPY docker/config.local.php ./config.local.php
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/start.sh /usr/local/bin/booktrack-start
COPY docker/uploads.htaccess ./uploads/.htaccess
COPY docker/private.htaccess ./uploads/pdfs/.htaccess
COPY docker/private.htaccess ./uploads/audio/.htaccess
COPY docker/private.htaccess ./storage/.htaccess

RUN sed -i 's/\r$//' /usr/local/bin/booktrack-start \
    && chmod +x /usr/local/bin/booktrack-start \
    && rm -rf docker .github \
    && find tests -type f ! -name make_sqlite.php ! -name .htaccess -delete \
    && mkdir -p /var/lib/booktrack \
    && chown -R www-data:www-data storage uploads /var/lib/booktrack

ENV PORT=10000
EXPOSE 10000
CMD ["booktrack-start"]
