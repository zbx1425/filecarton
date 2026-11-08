# Context is the repository root. See .dockerignore.
# The image tag is the full commit SHA, applied by the workflow, not here.

FROM node:22-bookworm AS frontend
WORKDIR /src
RUN corepack enable && corepack prepare pnpm@10.14.0 --activate
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
COPY apps/frontend/package.json apps/frontend/package.json
RUN pnpm install --frozen-lockfile
COPY apps/frontend apps/frontend
ENV NODE_OPTIONS=--max-old-space-size=4096
RUN pnpm build

FROM php:8.4-cli AS compile
WORKDIR /src
COPY apps/backend apps/backend
COPY scripts/compile_single_file.php scripts/compile_single_file.php
COPY --from=frontend /src/apps/frontend/dist apps/frontend/dist
RUN php scripts/compile_single_file.php

FROM php:8.4-apache
RUN set -eu; \
    apt-get update; \
    apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libzip-dev; \
    docker-php-ext-install -j"$(nproc)" mbstring zip opcache pcntl posix; \
    php -m | grep -qi '^curl$' || docker-php-ext-install curl; \
    php -m | grep -qi '^mbstring$'; \
    php -m | grep -qi '^zip$'; \
    php -m | grep -qi '^curl$'; \
    php -m | grep -qi '^pcntl$'; \
    php -m | grep -qi '^posix$'; \
    php -m | grep -qi '^Zend OPcache$' || php -m | grep -qi '^opcache$'; \
    rm -rf /var/lib/apt/lists/*; \
    mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/demo/cloud-run.ini /usr/local/etc/php/conf.d/zz-cloud-run.ini
COPY deploy/demo/rewrite-port.sed /tmp/rewrite-port.sed
# The sed file holds the literal ${PORT}. Apache expands it from the process environment.
RUN set -eu; \
    sed -i -f /tmp/rewrite-port.sed /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf; \
    rm -f /tmp/rewrite-port.sed; \
    grep -F "Listen $(printf '\044{PORT}')" /etc/apache2/ports.conf; \
    grep -F "*:$(printf '\044{PORT}')" /etc/apache2/sites-available/000-default.conf; \
    printf '%s\n' \
        'DirectoryIndex index.php' \
        '<Directory /var/www/html>' \
        '    Options -Indexes' \
        '</Directory>' \
        > /etc/apache2/conf-available/filecarton-demo.conf; \
    a2enconf filecarton-demo; \
    rm -f /var/www/html/index.html
COPY --from=compile /src/dist/singlefile/filecarton.php /var/www/html/index.php
COPY deploy/demo/bootstrap.php /opt/filecarton/bootstrap.php
COPY deploy/demo/demo_grant.php /opt/filecarton/demo_grant.php
COPY deploy/demo/filecarton.config.toml.php.template /opt/filecarton/filecarton.config.toml.php.template
COPY deploy/demo/motd.md /opt/filecarton/motd.md
COPY deploy/demo/content /opt/filecarton/seed
RUN test -f /opt/filecarton/seed/README.md \
    && test -f /opt/filecarton/seed/CREDITS.md \
    && test -d /opt/filecarton/seed/Photos
ARG IMAGE_SOURCE=https://github.com/local/filecarton
LABEL org.opencontainers.image.source="${IMAGE_SOURCE}"
ENV PORT=8080
EXPOSE 8080
ENTRYPOINT ["php", "/opt/filecarton/bootstrap.php"]
