# ============================================================================
# Sporty Ni Migo — Render.com deployment image
# ----------------------------------------------------------------------------
# Render has no native PHP runtime, so the app ships as a Docker web service.
# PHP 8.3 + Apache mirrors the app's Vercel runtime (vercel-php 0.7.3 = PHP 8.3)
# and the .htaccess in the project root keeps working unchanged.
#
# Render injects the listening port through $PORT (default 10000); the boot
# script rewrites Apache's Listen directive at startup (docker/start.sh).
# ============================================================================
FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html \
    PORT=10000

# pdo_mysql is the only required extension missing from the official image —
# curl, openssl, mbstring and fileinfo are already compiled in. opcache is
# enabled for production performance. mod_rewrite + mod_headers serve .htaccess.
RUN docker-php-ext-install pdo_mysql opcache \
    && a2enmod rewrite headers \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
        /etc/apache2/sites-available/*.conf \
        /etc/apache2/apache2.conf \
        /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html>\n    Options -Indexes +FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/zz-app-docroot.conf \
    && a2enconf zz-app-docroot \
    && printf 'ServerName sporty-ni-migo\n' > /etc/apache2/conf-available/zz-servername.conf \
    && a2enconf zz-servername

# Boot script is copied first so its layer stays cached while the app changes.
COPY docker/start.sh /usr/local/bin/docker-start.sh
RUN chmod 755 /usr/local/bin/docker-start.sh

COPY . /var/www/html/

# storage/ (logs) and uploads/ (venue & product images, voice notes) must be
# writable by the web server user. Note: the container filesystem is EPHEMERAL
# on Render — see render.yaml for the optional persistent disk.
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads

EXPOSE 10000

CMD ["/usr/local/bin/docker-start.sh"]
