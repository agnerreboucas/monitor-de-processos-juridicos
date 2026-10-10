FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends git unzip && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist
COPY . .
RUN chmod +x local/entrypoint.sh
EXPOSE 8080
ENTRYPOINT ["local/entrypoint.sh"]
