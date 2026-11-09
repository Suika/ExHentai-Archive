#!/bin/sh
# Background search index updates, run as root by supervisord (searchd only loads index files
# that a root process tells it about, which php-fpm/www-data can't).
#
# The app (classes/Search.php) and the entry point request work by creating trigger files:
#   reindex-full.request    full rebuild of all indexes from MariaDB (start-up, Cleanup, api.php?action=indexer)
#   reindex-delta.request   only galleries changed since the last indexing, merged into "galleries",
#                           plus the "suggested" tags (Archive, Audit, AddGallery, EditGallery)
# Requests that arrive while waiting or running are handled together by the next run; a pending full
# rebuild includes pending partial ones. The current index keeps serving searches until the new
# files are swapped in (--rotate).
#
# Request one by hand:  touch /var/www/exhen/tmp/reindex-full.request

CONF=/etc/manticoresearch/manticore.conf
DATA=/var/lib/sphinxsearch/data/exhen
DIR=${REINDEX_DIR:-/var/www/exhen/tmp}
POLL=${REINDEX_POLL:-5}

FULL=$DIR/reindex-full.request
DELTA=$DIR/reindex-delta.request
RUNNING=$DIR/reindex.running
HISTORY=$DIR/reindex.history   # one line per finished run ("full" or "delta"), used by the tests

log() {
    echo "reindexer: $*"
}

searchd_up() {
    mariadb --skip-ssl -h 127.0.0.1 -P 9306 -e 'show tables' >/dev/null 2>&1
}

# searchd swaps in *.new.* index files after --rotate; wait until it has done so
wait_rotation() {
    i=0
    while ls "$DATA"/*.new.* >/dev/null 2>&1 && [ "$i" -lt 3000 ]; do
        sleep 0.2
        i=$((i + 1))
    done
}

rm -f "$RUNNING"
log "waiting for requests in $DIR"

while true; do
    if [ ! -e "$FULL" ] && [ ! -e "$DELTA" ]; then
        sleep "$POLL"
        continue
    fi

    if ! searchd_up; then
        sleep "$POLL"
        continue
    fi

    touch "$RUNNING"
    sleep "$POLL" # requests arriving now are handled by this run

    if [ -e "$FULL" ]; then
        mode=full
        rm -f "$FULL" "$DELTA"
    else
        mode=delta
        rm -f "$DELTA"
    fi

    start=$(date +%s)
    log "$mode reindex started"

    if [ "$mode" = full ]; then
        indexer --config "$CONF" --all --rotate --quiet
        ret=$?
    else
        indexer --config "$CONF" --rotate --quiet galleries_delta suggested
        ret=$?
        if [ "$ret" -eq 0 ]; then
            wait_rotation
            indexer --config "$CONF" --merge galleries galleries_delta --rotate --quiet
            ret=$?
        fi
    fi
    wait_rotation

    log "$mode reindex finished in $(( $(date +%s) - start ))s (exit code $ret)"
    echo "$mode" >> "$HISTORY"
    rm -f "$RUNNING"
done
