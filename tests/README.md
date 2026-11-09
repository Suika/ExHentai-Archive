# Tests

PHPUnit tests that pin down what the app does at its boundaries, so internals (search backend, reindexing,
frontend, bug fixes) can be changed safely:

| Suite | What | Needs |
|---|---|---|
| `unit` | HTML parsers (gallery, feed index, archiver pages), search query syntax, tag diff of Audit, config, ExClient URL seam, image engine (libvips and GD) | nothing |
| `browser` | the UI in headless Chromium at desktop and phone size (touch, DPR 3): list, infinite scroll, search suggestions, single and multi page reader with keys, taps, swipes and the touch controls, delete switch | the running app stack, Chromium (test image only) |
| `integration` | API (list, search, filters, paging, gallery, suggestions, stats, reader pages, thumbnails, downloads, write calls) and the tasks (Archive, Audit/ForceAudit, Thumbnails, Cleanup, AddGallery, EditGallery, TaskRunner) | the running app stack (SQLite database), fake ExHentai |

`Support/Browser.php` drives Chromium (chrome-php/chrome); its `screenshot()` helps when changing the layout.

Integration tests talk to the app like its users do: HTTP through nginx and both php-fpm pools, and
`php TaskRunner.php` for tasks. ExHentai is replaced by a fake server (`fake-exhentai/router.php`) serving the
saved pages in `fixtures/exhentai/`, so tests never contact the real site.

## Running

Everything runs in Docker (`docker/docker-compose.test.yml`): the test image (the app image plus PHPUnit,
Dockerfile target `test`) with its SQLite database on a tmpfs.

```sh
cd docker
docker compose -f docker-compose.test.yml build                      # after Dockerfile/composer/docker config changes
docker compose -f docker-compose.test.yml run --rm tests             # all tests (~1.5 min)
docker compose -f docker-compose.test.yml run --rm tests --testsuite unit
docker compose -f docker-compose.test.yml run --rm tests --filter ApiReadTest
SHOW_STACK_LOG=1 docker compose -f docker-compose.test.yml run --rm tests    # print the app log on failure
docker compose -f docker-compose.test.yml down -v                    # remove the containers
```

`classes/`, `www/`, `TaskRunner.php`, `common.php`, `import-mysql-dump.php`, `export-mysql-dump.php`, `db.sql` and
`tests/` are mounted into the container,
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

None at the moment.

## Fixtures

`fixtures/exhentai/` holds real ExHentai pages, saved from the browser ("save page, HTML only"):

| File | Page |
|---|---|
| `gallery-<gid>.html` | gallery pages (every `gallery-*.html` gets a parser snapshot) |
| `gallery-newer.html`, `gallery-newer-older.html` | galleries with a "newer version" link (one and two newer versions) |
| `gallery-removed.html` | page shown for a removed gallery |
| `index-page.html`, `index-last.html` | search result page with a next page, and the last page (any list view works, these are Compact) |
| `archiver-form.html`, `archiver-continue.html` | archiver.php: the download form, and the page after "Download Original Archive" |

Saved pages contain account data. **Always run them through the sanitizer before adding them:**

```sh
php tests/tools/sanitize-fixture.php ~/saved.html tests/fixtures/exhentai/gallery-123.html
```

It replaces the API key/uid and the one-time H@H download link and warns about cookie-like values.
Check the result with `git diff` anyway.

`fixtures/archives/zip64.zip` is a small archive in ZIP64 format (like every zip over 4 GB), created with
`tests/tools/make-zip64.py`. All other test archives are generated (`Support/ZipFactory.php`).
