FROM php:8.2-apache

# mysqli is used throughout the app (db.php and every page that queries the DB).
# zip is used by includes/document_text.php to read a .docx's internal
# word/document.xml (a .docx is just a zip archive) when embedding case
# documents into a Case Report appendix - no Composer/PhpWord dependency
# needed for basic text extraction.
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install mysqli zip \
    && rm -rf /var/lib/apt/lists/*

# mysqldump/mysql client binaries - used by captains_quarters/backup_download.php
# and restore_process.php (System Management -> System Settings) to dump/
# restore the whole database as part of a full backup/restore. Shelled out to
# via proc_open rather than reimplementing a SQL dumper in PHP.
#
# poppler-utils provides pdftoppm (PDF page -> PNG) and pdftotext, used by
# includes/document_text.php to render a Case Report appendix - each page
# of an attached document as an image, so the report shows what the
# document actually looks like rather than reflowed plain text.
#
# libreoffice-writer provides `soffice --headless --convert-to pdf`, used
# to normalize .docx/.txt to PDF first so the same pdftoppm page-image
# pipeline handles every supported document type uniformly - the Writer
# component alone (not the full libreoffice metapackage) keeps this
# reasonably sized while still covering docx/doc/odt/rtf/txt conversion.
# All three are plain system packages baked into the image at build time,
# so the running container never needs network access for any of them -
# same offline-capable shape as everything else in this app.
RUN apt-get update \
    && apt-get install -y --no-install-recommends default-mysql-client poppler-utils libreoffice-writer \
    && rm -rf /var/lib/apt/lists/*

# AllowOverride for .htaccess, plus blocking direct HTTP access to schema/
# config/log files and the uploads-serving alias with PHP execution denied.
COPY docker/apache-hardening.conf /etc/apache2/conf-available/polaris-hardening.conf
COPY docker/apache-uploads.conf /etc/apache2/conf-available/polaris-uploads.conf
RUN mkdir -p /var/www/polaris-data/avatars \
             /var/www/polaris-data/exhibit-photos \
             /var/www/polaris-data/exhibit-documents \
             /var/www/polaris-data/report-cache \
    && a2enconf polaris-hardening polaris-uploads

# Registers DB-backed sessions (see includes/session_bootstrap.php) and
# disables displaying raw PHP errors to end users.
COPY docker/php-overrides.ini /usr/local/etc/php/conf.d/zz-polaris.ini

COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html /var/www/polaris-data

# Persist uploaded evidence across container recreates.
VOLUME /var/www/polaris-data

EXPOSE 80
