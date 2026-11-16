FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package*.json ./
RUN npm ci --ignore-scripts
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM dunglas/frankenphp:1-php8.5-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends supervisor unzip \
    && install-php-extensions pdo_pgsql pdo_sqlite pcntl bcmath intl zip opcache \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache /data /config \
    && chmod +x deployment/start.sh
COPY --from=frontend /app/public/build ./public/build
COPY deployment/Caddyfile /etc/caddy/Caddyfile
RUN frankenphp validate --config /etc/caddy/Caddyfile
COPY deployment/supervisord.conf /etc/supervisor/conf.d/studio.conf
COPY deployment/php.ini /usr/local/etc/php/conf.d/studio.ini
ENV APP_ENV=production APP_DEBUG=false SERVER_NAME=:8080
USER www-data
EXPOSE 8080
ENTRYPOINT ["/app/deployment/start.sh"]
