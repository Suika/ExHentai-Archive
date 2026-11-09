#!/bin/sh

echo "Preparing config files"
envsubst < /config.json > /var/www/exhen/config.json
printf '{"base":{"autoReadPercentage":%s,"allowDelete":%s}}\n' "${EX_READPERCENTAGE:-80}" "${EX_ALLOW_DELETE:-0}" > /var/www/exhen/www/config.json
cat /nginx.conf > /etc/nginx/nginx.conf

# SQLite database (data and search index in one file): created empty on the first start.
mkdir -p "$(dirname "$DB_PATH")"
if [ ! -e "$DB_PATH" ]; then
    echo "Creating the database $DB_PATH"
    sqlite3 "$DB_PATH" < /var/www/exhen/db.sql > /dev/null
fi

echo "Fixing folder permissions"
chown www-data:www-data /var/www/exhen/images /var/www/exhen/tmp
# SQLite writes next to the database file (-wal, -shm): the whole folder belongs to www-data
chown -R www-data:www-data "$(dirname "$DB_PATH")"
find /var/www/exhen -type d -exec chown www-data:www-data {} \;

# a command instead of the app, e.g. the migration from MariaDB:
#   docker compose run --rm exhen php import-mysql-dump.php data/dump.sql
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

echo "Starting supervisor"
exec supervisord -c /etc/supervisord.conf
