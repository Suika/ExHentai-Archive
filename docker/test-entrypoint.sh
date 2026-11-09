#!/bin/sh
# Entry point of the test image (Dockerfile target "test", docker-compose.test.yml).
# Starts the normal app stack (nginx, php-fpm pools, Manticore, memcached) plus the fake ExHentai server,
# then runs PHPUnit with the given arguments, e.g. "--testsuite unit" or "--filter ApiReadTest".
set -e

cd /var/www/exhen

# The fake ExHentai server replaces https://exhentai.org (ExClient "exhentai" config) and the request
# throttle is disabled, so tasks never contact the real site and don't sleep.
sed -i 's|"base": {|"base": {\n        "exhentai": {"baseUrl": "'"${FAKE_EXHENTAI_URL}"'", "throttle": 0, "downloadTimeout": 2},|' /config.json

echo "Waiting for the database ${DB_HOST}"
i=0
until mariadb --skip-ssl -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" -e 'select 1' "$DB_NAME" >/dev/null 2>&1; do
    i=$((i + 1))
    if [ "$i" -gt 120 ]; then
        echo "Database not reachable" >&2
        exit 1
    fi
    sleep 1
done
mariadb --skip-ssl -h "$DB_HOST" -u "$DB_USER" -p"$DB_PASS" < db.sql

# The app entry point writes the configs, builds the indexes and runs supervisord
sh /entrypoint.sh > /var/log/exhen-stack.log 2>&1 &

mkdir -p "$FAKE_EXHENTAI_STATE"
php -S "${FAKE_EXHENTAI_URL#http://}" tests/fake-exhentai/router.php > /var/log/fake-exhentai.log 2>&1 &

echo "Waiting for the app stack"
i=0
until wget -q -O /dev/null "http://127.0.0.1:${NGINX_PORT}/index.html" 2>/dev/null \
    && mariadb --skip-ssl -h 127.0.0.1 -P 9306 -e 'show tables' >/dev/null 2>&1 \
    && [ -S /run/php/php8.4-fpm-images.sock ]; do
    i=$((i + 1))
    if [ "$i" -gt 120 ]; then
        echo "App stack did not start, log:" >&2
        cat /var/log/exhen-stack.log >&2
        exit 1
    fi
    sleep 1
done

set +e
vendor/bin/phpunit "$@"
ret=$?

if [ "$ret" -ne 0 ] && [ -n "$SHOW_STACK_LOG" ]; then
    echo "----- app stack log -----"
    cat /var/log/exhen-stack.log
fi

exit $ret
