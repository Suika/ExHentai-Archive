# Exhentai-Archive on Docker

A single container to run [ExHentai-Archive](https://github.com/Suika/ExHentai-Archive).

The ENVs with __REPLACEME__ need the right parameters. Most you can get out of the cookies.
SP is your viewing profile (create a second profile and you will get the key in the cookie), 
the system needs the "Front Page Settings" to be set to Minimal if you want to use the feed feature.

__EX_ALLOW_DELETE__=1 enables the Delete action in the reader (it asks for __EX_ACCESSKEY__); off by default.

__EX_ACCESSKEY__ is the key that will be used by the [userscript.user.js](https://github.com/Sn0wCrack/ExHentai-Archive/blob/master/userscript.user.js)

Two versions:
- __master__ branch: SQLite, data and search index in one file. No separate DB container.
- __mysql__ branch: MariaDB + Manticore (the older setup).

## 1. SQLite (master)

The database is created on the first start in the data volume. Search updates right away when a gallery is stored.

Volumes:

- /var/www/exhen/data:       SQLite database (exhen.sqlite)
- /var/www/exhen/images:     Thumb of first image in the archive
- /var/www/exhen/archive:    Location where the archive and pages will be saved
- /var/www/exhen/tmp:        TMP files

Compose:
```
services:
  exhen:
    image: suika/exhentai-archive:latest
    container_name: exhen
    restart: unless-stopped
    ports:
      - 6767:6767
    tmpfs:
      - /var/www/exhen/tmp
    volumes:
      - ./exhen/data:/var/www/exhen/data
      - ./exhen/images:/var/www/exhen/images
      - ./exhen/archive:/var/www/exhen/archive
    environment:
      - EX_ACCESSKEY=UGANDA
      - NGINX_PORT=6767
      - EX_MEMBERID=you
      - EX_PASSHASH=wish
      - EX_IGNEOUS=suuuuure
      - EX_SK=hah
      - EX_SP=2
      - TZ=Europe/London
```

ENV:
```
DB_PATH=/var/www/exhen/data/exhen.sqlite

EX_READPERCENTAGE=80
EX_ARCHDIR=/var/www/exhen/archive
EX_IMGDIR=/var/www/exhen/images
EX_TEMPDIR=/var/www/exhen/tmp
EX_VIEWTYPE=mpv
EX_MEMBERID=REPLACEME
EX_PASSHASH=REPLACEME
EX_ACCESSKEY=REPLACEME
EX_ALLOW_DELETE=0
EX_IGNEOUS=REPLACEME
EX_SK=REPLACEME
EX_SP=REPLACEME
EX_HATHPERKS=m1.m2.m3.tf.t1.t2.t3.p1.p2.s-210aa44613

NGINX_PORT=80
DOLLAR='$'
TZ=Europe/London
```

Build:
```
cd docker
docker compose build
```

Rebuild the search index by hand (normally never needed): `api.php?action=indexer`

## 2. Migration from MySQL/MariaDB to SQLite

Ids are kept, so the images and archive folders stay as they are.

1. Stop the old app container.
2. Dump the database into the new data folder (`mariadb-dump` on MariaDB 11+):
   ```
   mysqldump -h <host> -u exhen -p exhen > ./exhen/data/dump.sql
   ```
3. Import it, before the first start of the SQLite container (it refuses a database that already has galleries):
   ```
   docker compose run --rm exhen php import-mysql-dump.php data/dump.sql
   ```
4. Start: `docker compose up -d`. The dump can be deleted afterwards.

Back to MySQL/MariaDB: stop the app, then
```
docker compose run --rm exhen php export-mysql-dump.php data/dump.sql
mysql -h <host> -u exhen -p exhen < ./exhen/data/dump.sql
```
and run the mysql branch image (part 3) on that database.

## 3. MySQL/MariaDB (mysql branch)

DB needs to be initialized by hand, just use db.sql from the mysql branch.

Volumes:

- /var/www/exhen/images:     Thumb of first image in the archive
- /var/www/exhen/archive:    Location where the archive and pages will be saved
- /var/www/exhen/tmp:        TMP files
- /var/lib/sphinxsearch/data/exhen: sphinx data

Compose:
```
services:
  exhen-db:
    image: mariadb:11.4
    container_name: exhen-db
    command: [ '--wait_timeout=28800', ]
    ports:
      - 3306:3306
    volumes:
      - ./exhen-db:/var/lib/mysql
    environment:
      - MARIADB_AUTO_UPGRADE=1
    restart: always
  exhen:
    image: suika/exhentai-archive:mysql
    container_name: exhen
    restart: unless-stopped
    depends_on:
      - exhen-db
    ports:
      - 6767:6767
    tmpfs:
      - /var/www/exhen/tmp
    volumes:
      - ./exhen/sphinx:/var/lib/sphinxsearch/data/exhen
      - ./exhen/images:/var/www/exhen/images
      - ./exhen/archive:/var/www/exhen/archive
    environment:
      - DB_HOST=exhen-db
      - DB_NAME=exhen
      - DB_USER=exhen
      - DB_PASS=exhen
      - EX_ACCESSKEY=UGANDA
      - NGINX_PORT=6767
      - EX_MEMBERID=you
      - EX_PASSHASH=wish
      - EX_IGNEOUS=suuuuure
      - EX_SK=hah
      - EX_SP=2
      - TZ=Europe/London
```

ENV, in addition to the ones from part 1 (without DB_PATH):
```
DB_HOST=mariadb
DB_NAME=exhen
DB_USER=exhen
DB_PASS=pass
DB_PORT=3306

SPHINX_HOST=127.0.0.1
SPHINX_NAME=exhen
SPHINX_USER=exhen
SPHINX_PASS=""
SPHINX_PORT=9306
```

Build:
```
git checkout mysql
cd docker
docker build -t suika/exhentai-archive:mysql -f Dockerfile ..
```

Search index:
- First start (no index in the sphinx volume): the indexes are built before the app starts.
- Later starts: the app starts right away with the existing index; a full rebuild runs in the background
  and replaces it when done.
- Read/colour changes and deletions are applied to the search index immediately. Archive, Audit, AddGallery
  and EditGallery request a partial update (only changed galleries) and Cleanup a full rebuild; a background
  worker (`reindexer` in supervisord, `/reindexer.sh`) runs them one at a time. `REINDEX_POLL` (default 5)
  is how often it checks, in seconds.
- Request a full rebuild by hand: `docker exec exhen touch /var/www/exhen/tmp/reindex-full.request`

## Tests

```
cd docker
docker compose -f docker-compose.test.yml run --rm tests
```
See [tests/README.md](../tests/README.md). On the mysql branch the tests use a MariaDB container.
