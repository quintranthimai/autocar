FROM php:8.2-cli

RUN apt-get update -y && apt-get install -y libpq-dev unzip git
RUN docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . /app

RUN composer install --no-dev --optimize-autoloader
RUN chmod -R 777 /app/storage /app/bootstrap/cache

CMD php artisan serve --host=0.0.0.0 --port=${PORT:-10000}
