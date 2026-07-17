FROM php:8.2-apache

# mysqli for DB access, zip for reading .docx files.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install mysqli zip \
    && rm -rf /var/lib/apt/lists/*

# mysql client for backup/restore, poppler-utils + libreoffice-writer for
# converting/rendering document previews in the Case Report.
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client poppler-utils libreoffice-writer \
    && rm -rf /var/lib/apt/lists/*

# Apache hardening/upload config.
COPY docker/apache-hardening.conf /etc/apache2/conf-available/polaris-hardening.conf
COPY docker/apache-uploads.conf /etc/apache2/conf-available/polaris-uploads.conf
RUN mkdir -p /var/www/polaris-data/avatars \
             /var/www/polaris-data/exhibit-photos \
             /var/www/polaris-data/exhibit-documents \
             /var/www/polaris-data/report-cache \
    && a2enconf polaris-hardening polaris-uploads

# DB-backed sessions and PHP error display settings.
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-polaris.ini

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html /var/www/polaris-data

# Persisted evidence storage.
VOLUME /var/www/polaris-data

EXPOSE 80
