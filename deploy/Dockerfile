# Imagem de produção: Apache + PHP 8.3 (o .htaccess do projeto já faz o roteamento).
FROM php:8.3-apache
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip mariadb-client \
 && rm -rf /var/lib/apt/lists/* \
 && docker-php-ext-install pdo_mysql \
 && (command -v mysqldump >/dev/null || ln -s "$(command -v mariadb-dump)" /usr/local/bin/mysqldump) \
 && a2enmod rewrite headers
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && printf '<Directory /var/www/html/public>\n  AllowOverride All\n  Require all granted\n</Directory>\nServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/radar.conf \
 && a2enconf radar
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY radar-trabalhista/composer.json radar-trabalhista/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist
COPY radar-trabalhista/ ./
RUN mkdir -p storage/backups && chown -R www-data:www-data storage
