#!/bin/sh
# ============================================================================
# Boot script for the Render Docker web service.
# Apache must listen on the port Render assigns through $PORT (default 10000).
# ============================================================================
set -e

: "${PORT:=10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf

# Writable state folders (the image ships them empty; a Render persistent disk,
# when attached in render.yaml, is mounted over uploads/).
mkdir -p /var/www/html/storage/logs /var/www/html/uploads
chown -R www-data:www-data /var/www/html/storage /var/www/html/uploads

exec apache2-foreground
