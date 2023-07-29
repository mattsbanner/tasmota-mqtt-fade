FROM composer:2 as composer

WORKDIR /app

COPY composer.json ./
COPY composer.lock ./

RUN composer install --no-interaction --no-scripts --prefer-dist --no-dev


FROM php:8.2-cli-alpine3.18

RUN apt-get update && apt-get install -y supervisor

WORKDIR /app

COPY fade.php ./
COPY src src
COPY supervisord.conf /etc/supervisor/supervisord.conf
COPY --from=composer /app/vendor/ vendor/

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
