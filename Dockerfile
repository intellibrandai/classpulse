FROM php:8.3-cli-bookworm
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip curl libzip-dev \
 && docker-php-ext-install pdo_mysql zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
