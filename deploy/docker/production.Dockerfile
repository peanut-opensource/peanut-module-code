# syntax=docker/dockerfile:1.7

ARG PEANUT_SOURCE_COMMIT
ARG PEANUT_SOURCE_TREE

FROM node:22.23.2-bookworm-slim AS client-base

# Development locks may select local Core archives. A formal Edition has registry
# locks and no local archives; copy development inputs only when they exist.
RUN --mount=type=bind,source=.,target=/build-context,readonly \
    if [ -d /build-context/packages/core-web ]; then \
        mkdir -p /build/packages/core-web; \
        find /build-context/packages/core-web -maxdepth 1 -type f -name '*.tgz' \
            -exec cp -t /build/packages/core-web/ {} +; \
    fi

FROM client-base AS admin-builder

WORKDIR /build/web
COPY web/package.json web/pnpm-lock.yaml ./
RUN corepack enable && corepack prepare "$(node -p "require('./package.json').packageManager")" --activate
RUN HUSKY=0 pnpm install --frozen-lockfile
COPY modules.lock /build/modules.lock
COPY web/ ./
RUN printf '%s\n' 'VITE_DEPLOYMENT_MODE=standalone' 'VITE_API_BASE_URL=' > .env.standalone \
    && printf '%s\n' 'VITE_DEPLOYMENT_MODE=multi-tenant' 'VITE_API_BASE_URL=' > .env.multi-tenant \
    && pnpm exec vue-tsc --noEmit \
    && pnpm exec vite build --mode multi-tenant --outDir dist/multi-tenant

FROM client-base AS mobile-builder

WORKDIR /build/uniapp
COPY uniapp/package.json uniapp/package-lock.json ./
RUN npm ci
COPY uniapp/ ./
RUN npm run build:h5

FROM client-base AS platform-builder

WORKDIR /build/platform
COPY platform/package.json platform/package-lock.json ./
RUN npm ci
COPY platform/ ./
RUN npm run build

FROM client-base AS pc-builder

WORKDIR /build/pc
COPY pc/package.json pc/package-lock.json ./
RUN npm ci
COPY pc/ ./
RUN npm run build \
    && mv .output /build/pc-ssr-output \
    && NUXT_PC_RENDER_MODE=spa npm run generate

FROM node:22.23.2-bookworm-slim AS pc

ARG PEANUT_SOURCE_COMMIT
ARG PEANUT_SOURCE_TREE
LABEL org.opencontainers.image.revision=$PEANUT_SOURCE_COMMIT \
      org.peanut-admin.source-tree=$PEANUT_SOURCE_TREE

ENV NODE_ENV=production \
    HOST=0.0.0.0 \
    PORT=3000

WORKDIR /app
COPY --from=pc-builder /build/pc-ssr-output ./.output

USER node
EXPOSE 3000
CMD ["node", ".output/server/index.mjs"]

FROM composer:2.10.2 AS composer-deps

RUN php -r 'exit(extension_loaded("bcmath") ? 0 : 1);' || docker-php-ext-install bcmath

WORKDIR /build/server
COPY server/composer.json server/composer.lock ./
COPY server/app app
COPY server/database/schema database/schema
RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --no-scripts

FROM php:8.3-fpm-bookworm AS php

ARG PEANUT_DEPLOYMENT_RECEIPT_BASE64=""
ARG PEANUT_SOURCE_COMMIT
ARG PEANUT_SOURCE_TREE
LABEL org.opencontainers.image.revision=$PEANUT_SOURCE_COMMIT \
      org.peanut-admin.source-tree=$PEANUT_SOURCE_TREE

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libzip-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath curl mbstring pdo_mysql simplexml zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/peanut-admin

COPY deploy/docker/php-upload.ini /usr/local/etc/php/conf.d/peanut-upload.ini

# The fixed Edition installer is the production build context. Copy its whole
# secret-filtered application tree so package-shipped install/upgrade runtimes,
# generated API metadata, public docs, dependency locks and .peanut ownership
# baselines are identical in the image and the installed instance.
COPY . .
COPY LICENSE NOTICE THIRD_PARTY_NOTICES.md RELEASE_SBOM.spdx.json CHANGELOG.md RELEASE_METADATA.json legal/
COPY --from=composer-deps /build/server/vendor server/vendor
COPY deploy/docker/php-entrypoint.sh /usr/local/bin/peanut-php-entrypoint
COPY --chmod=0555 deploy/docker/read-backend-enum.sh /usr/local/bin/peanut-read-backend-enum

RUN if [ -n "$PEANUT_DEPLOYMENT_RECEIPT_BASE64" ]; then \
        printf '%s' "$PEANUT_DEPLOYMENT_RECEIPT_BASE64" | base64 --decode > DEPLOYMENT_RECEIPT.json; \
        chmod 0444 DEPLOYMENT_RECEIPT.json; \
    fi \
    && mkdir -p server/runtime server/public/storage server/private/storage server/private/installation \
    && cd server \
    && printf '%s\n' \
        'APP_ENV=production' \
        'APP_DEBUG=false' \
        'PEANUT_DATABASE_RESOURCE_ID=peanut-admin-build-only' \
        'DEPLOYMENT_MODE=standalone' \
        'DB_HOST=build-only.invalid' \
        'DB_PORT=3306' \
        'DB_NAME=build_only' \
        'DB_USER=build_only' \
        'DB_PASS=build-only' \
        'DB_PREFIX=pa_' > .env.image-build \
    && chmod 600 .env.image-build \
    && PHP_ENV_NAME=image-build php think service:discover \
    && PHP_ENV_NAME=image-build php think vendor:publish \
    && rm -f .env.image-build \
    && cd .. \
    && chmod +x server/think server/database/seed-demo-data.php /usr/local/bin/peanut-php-entrypoint \
    && ln -s /var/www/peanut-admin/server/database/seed-demo-data.php /usr/local/bin/peanut-seed-demo-data \
    && chown -R www-data:www-data server/runtime server/public/storage server/private/storage server/private/installation

EXPOSE 9000

FROM nginx:1.28.0-alpine AS nginx

ARG PEANUT_SOURCE_COMMIT
ARG PEANUT_SOURCE_TREE
LABEL org.opencontainers.image.revision=$PEANUT_SOURCE_COMMIT \
      org.peanut-admin.source-tree=$PEANUT_SOURCE_TREE

COPY deploy/nginx/peanut-admin.conf /etc/nginx/conf.d/default.conf
COPY deploy/nginx/peanut-admin-ssr.conf /etc/nginx/peanut-admin-ssr.conf
COPY deploy/docker/nginx-select-pc.sh /docker-entrypoint.d/41-select-pc.sh
COPY deploy/docker/nginx-select-admin.sh /docker-entrypoint.d/40-select-admin.sh
COPY --chmod=0555 deploy/docker/read-backend-enum.sh /usr/local/bin/peanut-read-backend-enum
COPY server/public /var/www/peanut-admin/server/public
COPY LICENSE NOTICE THIRD_PARTY_NOTICES.md RELEASE_SBOM.spdx.json CHANGELOG.md RELEASE_METADATA.json /var/www/peanut-admin/server/public/legal/
COPY --from=admin-builder /build/web/dist /opt/peanut-admin/admin
COPY --from=platform-builder /build/platform/dist /var/www/peanut-admin/server/public/platform
COPY --from=mobile-builder /build/uniapp/dist/build/h5 /var/www/peanut-admin/server/public/mobile
COPY --from=pc-builder /build/pc/.output/public /var/www/peanut-admin/server/public/pc
RUN chmod +x /docker-entrypoint.d/40-select-admin.sh \
    && chmod +x /docker-entrypoint.d/41-select-pc.sh \
    && mkdir -p /var/www/peanut-admin/server/public/storage
