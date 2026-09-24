# PlayTube PHP application image (Azure App Service F1 / generic Linux host).
# Build (from repo root):
#   docker build -t playtube/playtube:<tag> .
# Settings are injected at runtime via env vars (see config.php), so the same
# image serves local dev and Azure. Media must be written to object storage
# (azure_blob_storage / S3) because the app file system is ephemeral.
FROM php:8.2-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
    PHP_UPLOAD_MAX_FILESIZE=2G \
    PHP_POST_MAX_SIZE=2G \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=3600 \
    PHP_MAX_INPUT_VARS=3000

RUN apt-get update && apt-get install -y --no-install-recommends \
        ffmpeg \
        zip unzip \
        libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        libzip-dev libcurl4-openssl-dev pkg-config \
        libonig-dev libxml2-dev libexif-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install mysqli pdo_mysql gd zip mbstring curl xml exif bcmath intl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Point Apache at the app docroot and enable .htaccess (mod_rewrite rules).
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
 && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
 && sed -ri -e 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . .

RUN chown -R www-data:www-data /var/www/html \
 && find /var/www/html -type d -exec chmod 755 {} + \
 && find /var/www/html -type f -exec chmod 644 {} + \
 && chmod -R u+rwX /var/www/html/upload /var/www/html/cache 2>/dev/null

EXPOSE 80