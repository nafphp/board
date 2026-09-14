# Based on fkde/aspx: Alpine, native PHP packages, nginx and supervisor.
ARG ALPINE_IMAGE=alpine:3.24.1@sha256:28bd5fe8b56d1bd048e5babf5b10710ebe0bae67db86916198a6eec434943f8b
FROM ${ALPINE_IMAGE} AS runtime
RUN apk add --no-cache ca-certificates curl nginx supervisor tzdata \
    php85 php85-fpm php85-pdo_mysql php85-pdo_pgsql php85-pdo_sqlite \
    php85-session php85-mbstring php85-openssl php85-curl php85-dom php85-xml \
    php85-xmlwriter php85-simplexml php85-fileinfo php85-tokenizer php85-ctype \
    php85-iconv php85-phar php85-ldap php85-intl php85-sodium \
    && ln -sf /usr/bin/php85 /usr/bin/php \
    && addgroup -g 1000 www && adduser -D -u 1000 -G www www \
    && mkdir -p /workspace/app/storage /tmp/nginx /tmp/php \
    && chown -R www:www /workspace /tmp/nginx /tmp/php
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/php.ini /etc/php85/conf.d/99-nafinity.ini
COPY docker/php-fpm.conf /etc/php85/php-fpm.conf
COPY docker/supervisord.conf /etc/supervisord.conf
WORKDIR /workspace/app
USER www
EXPOSE 8080
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s CMD curl -fsS http://127.0.0.1:8080/health/live || exit 1
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]

FROM runtime AS development
USER root
RUN apk add --no-cache composer git \
    && printf '\nopcache.validate_timestamps=1\nopcache.revalidate_freq=0\n' >> /etc/php85/conf.d/99-nafinity.ini
USER www

# Vendor must be prepared by a verified distribution install; never copy Source-mode links.
FROM runtime AS production
COPY --chown=www:www app/ /workspace/app/

RUN test -f composer.lock && test -f vendor/.nafinity-distribution \
    && test -z "$(find vendor -type l -print -quit)" \
    && mkdir -p storage/sessions storage/logs storage/attachments storage/queue storage/schedule storage/oauth
