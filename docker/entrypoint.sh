#!/bin/sh

echo "Preparing config files"
envsubst < /config.json > /var/www/exhen/config.json
envsubst < /manticore.conf > /etc/manticoresearch/manticore.conf
printf '{"base":{"autoReadPercentage":%s}}\n' "${EX_READPERCENTAGE:-80}" > /var/www/exhen/www/config.json
mkdir -p /etc/manticoresearch /run/manticore /var/lib/sphinxsearch/data/exhen
cat /nginx.conf > /etc/nginx/nginx.conf

echo "Building all search indexes"
indexer --config /etc/manticoresearch/manticore.conf --all

echo "Fixing folder permissions"
chown manticore:manticore -R /var/lib/sphinxsearch/data /run/manticore
chown www-data:www-data /var/www/exhen/images /var/www/exhen/tmp
find /var/www/exhen -type d -exec chown www-data:www-data {} \;

echo "Starting supervisor"
exec supervisord -c /etc/supervisord.conf
