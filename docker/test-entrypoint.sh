#!/bin/sh
# Entry point of the test image (Dockerfile target "test", docker-compose.test.yml).
# Starts the normal app stack (nginx, php-fpm pools, memcached) plus the fake ExHentai server,
# then runs PHPUnit with the given arguments, e.g. "--testsuite unit" or "--filter ApiReadTest".
set -e

cd /var/www/exhen

# The fake ExHentai server replaces https://exhentai.org (ExClient "exhentai" config) and the request
# throttle is disabled, so tasks never contact the real site and don't sleep.
sed -i 's|"base": {|"base": {\n        "exhentai": {"baseUrl": "'"${FAKE_EXHENTAI_URL}"'", "throttle": 0, "downloadTimeout": 2, "requestTimeout": 2, "connectTimeout": 2, "warnWait": 0, "warnRetries": 3},|' /config.json

# The app entry point writes the configs, creates the database and runs supervisord
sh /entrypoint.sh > /var/log/exhen-stack.log 2>&1 &

mkdir -p "$FAKE_EXHENTAI_STATE"
# several workers: a hanging page (tests with "sleep") must not hold up the next request, as on the real site
PHP_CLI_SERVER_WORKERS=4 php -S "${FAKE_EXHENTAI_URL#http://}" tests/fake-exhentai/router.php > /var/log/fake-exhentai.log 2>&1 &

echo "Waiting for the app stack"
i=0
until wget -q -O /dev/null "http://127.0.0.1:${NGINX_PORT}/index.html" 2>/dev/null \
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
