FROM php:8.2-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libpq-dev libzip-dev zip \
  && docker-php-ext-install pdo_pgsql zip \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --prefer-dist

COPY . .

RUN composer dump-autoload --optimize --no-dev \
  && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
  && chmod -R 775 storage bootstrap/cache

# Démarrage : diagnostic du grand livre (lecture seule, rapport dans les logs, jamais bloquant)
# avant les migrations — DA-17, cf. CLAUDE.md T-04.
# schedule:work : tâches quotidiennes (retards de cotisation et de prêt).
CMD ["sh", "-c", "php artisan config:cache && php artisan route:cache && (php artisan kafo:diagnose-ledger || true) && php artisan migrate --force && php artisan db:seed --class=RolesSeeder --force && php artisan db:seed --class=DevSuperAdminSeeder --force && php artisan queue:work --tries=3 --sleep=2 --backoff=10 >/proc/1/fd/1 2>&1 & php artisan schedule:work >/proc/1/fd/1 2>&1 & php artisan serve --host=0.0.0.0 --port=${PORT:-10000}"]
