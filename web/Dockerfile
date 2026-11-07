# Loft web app — production image (Apache + PHP 8.3). Used by render.yaml; runs on any Docker host.
#   docker build -t loft web/
#   docker run -p 8080:8080 --env-file web/.env loft            # web server
#   docker run --env-file web/.env loft /usr/local/bin/start worker

# ---- PHP dependencies -------------------------------------------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction

# ---- Runtime -----------------------------------------------------------------------------------
FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev \
 && docker-php-ext-install -j"$(nproc)" pdo_pgsql opcache pcntl \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/* \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && a2enmod rewrite

COPY docker/php.ini "$PHP_INI_DIR/conf.d/zz-loft.ini"
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/start.sh /usr/local/bin/start
# Apache listens on $PORT (Render sets it; defaults to 8080 in start.sh).
RUN sed -i 's/^Listen 80$/Listen ${PORT}/' /etc/apache2/ports.conf \
 && printf 'ServerName localhost\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/zz-loft.conf \
 && a2enconf zz-loft \
 && chmod +x /usr/local/bin/start

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html

ENV PORT=8080
EXPOSE 8080
CMD ["/usr/local/bin/start", "web"]
