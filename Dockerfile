FROM dunglas/frankenphp:1-php8.4 AS base
ENV APP_ENV=prod
WORKDIR /app
RUN install-php-extensions mongodb intl opcache \
 && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

FROM base AS build
RUN install-php-extensions zip
# Only so the kernel can boot at build time. The driver connects lazily, and the
# real value comes from secrets/app.env at runtime. This stage isn't shipped.
ENV MONGODB_URL=mongodb://localhost:27017
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative \
 && composer run-script --no-dev post-install-cmd \
 && php bin/console asset-map:compile \
 && php bin/console cache:warmup

FROM base AS prod
ENV SERVER_NAME=:8080
COPY --from=build /app /app
RUN useradd -r -u 10001 app \
 && chown -R app:app /app/var /data/caddy /config/caddy
USER app
EXPOSE 8080
