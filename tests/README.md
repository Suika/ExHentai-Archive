# Tests

PHPUnit tests that pin down what the app does at its boundaries, so internals (search backend, reindexing,
frontend, bug fixes) can be changed safely:

| Suite | What | Needs |
|---|---|---|
| `unit` | HTML parsers (gallery, feed index, archiver pages), search escaping, tag diff of Audit, config, ExClient URL seam, image engine (libvips and GD) | nothing |
| `integration` | API (list, search, filters, paging, gallery, suggestions, stats, reader pages, thumbnails, downloads, write calls) and the tasks (Archive, Audit/ForceAudit, Thumbnails, Cleanup, AddGallery, EditGallery, TaskRunner) | the running app stack, MariaDB, fake ExHentai |

Integration tests talk to the app like its users do: HTTP through nginx and both php-fpm pools, and
`php TaskRunner.php` for tasks. ExHentai is replaced by a fake server (`fake-exhentai/router.php`) serving the
saved pages in `fixtures/exhentai/`, so tests never contact the real site.

## Running

Everything runs in Docker (`docker/docker-compose.test.yml`): a MariaDB container and the test image
(the app image plus PHPUnit, Dockerfile target `test`).

```sh
cd docker
docker compose -f docker-compose.test.yml build                      # after Dockerfile/composer/docker config changes
docker compose -f docker-compose.test.yml run --rm tests             # all tests (~30 s)
docker compose -f docker-compose.test.yml run --rm tests --testsuite unit
docker compose -f docker-compose.test.yml run --rm tests --filter ApiReadTest
TEST_MARIADB=11.4 docker compose -f docker-compose.test.yml run --rm tests   # default is 10.3 (prod)
SHOW_STACK_LOG=1 docker compose -f docker-compose.test.yml run --rm tests    # print the app log on failure
docker compose -f docker-compose.test.yml down -v                    # remove the containers
```

`classes/`, `www/`, `TaskRunner.php`, `common.php`, `db.sql` and `tests/` are mounted into the container,
so code and test changes don't need a rebuild.

Every integration test class starts from an empty database (`db.sql`), archive, images, cache and search
index, and creates its data through the app itself (API, Archive, AddGallery, EditGallery).

## Snapshots

`snapshots/` holds the expected parser output and API responses (without timings, timestamps of the run,
cache flags and archive sizes). A missing snapshot is written and the test is marked incomplete. After an
intended change, rewrite them and review the diff:

```sh
UPDATE_SNAPSHOTS=1 docker compose -f docker-compose.test.yml run --rm tests
git diff tests/snapshots
```

## Known bugs

Tests for bugs that are not fixed yet are in the group `known-bug`, excluded by default. They fail now and
should pass once the bug is fixed; remove the `#[Group('known-bug')]` attribute then.

```sh
docker compose -f docker-compose.test.yml run --rm tests --group known-bug
```

| Test | Bug |
|---|---|
| `IndexParserTest::testPageWithNextPage`, `testLastPage`, `ArchiveTaskTest::testFeedCrawl` | feed crawl doesn't understand current ExHentai search pages (Compact view, `?next=` pagination) |
| `ApiWriteTest::testUnknownGalleryIsNotCreated` | B8: update/updateColor/deletegallery with an unknown id create empty galleries |
| `ApiWriteTest::testReadFilterFollowsUpdateWithoutManualReindex` | B9: reindex started by the web request is never loaded by searchd |
| `ApiImageTest::testContentTypeMatchesThePageFormat` | B16: Content-Type from the case-sensitive extension, WebP sent as image/jpeg |

## Fixtures

`fixtures/exhentai/` holds real ExHentai pages, saved from the browser ("save page, HTML only"):

| File | Page |
|---|---|
| `gallery-<gid>.html` | gallery pages (every `gallery-*.html` gets a parser snapshot) |
| `gallery-newer.html`, `gallery-newer-older.html` | galleries with a "newer version" link (one and two newer versions) |
| `gallery-removed.html` | page shown for a removed gallery |
| `index-page.html`, `index-last.html` | search result page with a next page, and the last page |
| `archiver-form.html`, `archiver-continue.html` | archiver.php: the download form, and the page after "Download Original Archive" |

Saved pages contain account data. **Always run them through the sanitizer before adding them:**

```sh
php tests/tools/sanitize-fixture.php ~/saved.html tests/fixtures/exhentai/gallery-123.html
```

It replaces the API key/uid and the one-time H@H download link and warns about cookie-like values.
Check the result with `git diff` anyway.

`fixtures/archives/zip64.zip` is a small archive in ZIP64 format (like every zip over 4 GB), created with
`tests/tools/make-zip64.py`. All other test archives are generated (`Support/ZipFactory.php`).
