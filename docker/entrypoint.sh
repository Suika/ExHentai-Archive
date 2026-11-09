#!/bin/sh

echo "Preparing config files"
envsubst < /config.json > /var/www/exhen/config.json
envsubst < /manticore.conf > /etc/manticoresearch/manticore.conf
printf '{"base":{"autoReadPercentage":%s,"allowDelete":%s}}\n' "${EX_READPERCENTAGE:-80}" "${EX_ALLOW_DELETE:-0}" > /var/www/exhen/www/config.json
mkdir -p /etc/manticoresearch /run/manticore /var/lib/sphinxsearch/data/exhen
cat /nginx.conf > /etc/nginx/nginx.conf

# Search indexes: only indexes that don't exist yet are built here (searchd can't serve without them).
# Existing ones keep serving and are refreshed in the background by the reindex worker after start-up.
DATA=/var/lib/sphinxsearch/data/exhen
missing=""
for index in galleries galleries_delta suggested; do
    [ -e "$DATA/$index.sph" ] || missing="$missing $index"
done
if [ -n "$missing" ]; then
    echo "Building search indexes:$missing"
    indexer --config /etc/manticoresearch/manticore.conf $missing
fi

echo "Fixing folder permissions"
chown manticore:manticore -R /var/lib/sphinxsearch/data /run/manticore
chown www-data:www-data /var/www/exhen/images /var/www/exhen/tmp
find /var/www/exhen -type d -exec chown www-data:www-data {} \;

# a fresh start just built everything; otherwise bring the existing indexes up to date
if [ "$missing" != " galleries galleries_delta suggested" ]; then
    echo "Requesting a full search index rebuild in the background"
    touch /var/www/exhen/tmp/reindex-full.request
fi

echo "Starting supervisor"
exec supervisord -c /etc/supervisord.conf
