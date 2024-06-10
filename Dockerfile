# Dockerfile

FROM php:8.2-cli

RUN apt-get update && apt-get install -y \
    libpq-dev \
    curl \
    && docker-php-ext-install pdo pdo_pgsql pcntl

WORKDIR /app

COPY . .

CMD ["php", "index.php"]
