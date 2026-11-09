-- ExHentai-Archive database schema (SQLite)
-- Same tables and columns as the former MariaDB schema, so import-mysql-dump.php copies a MariaDB dump
-- 1:1 (ids included). Dates are stored as text 'YYYY-MM-DD HH:MM:SS'.
--
-- Create a database:  sqlite3 exhen.sqlite < db.sql   (the Docker entry point does this on the first start)

-- readers (web) don't block the writer (tasks) and the other way round; stored in the database file
PRAGMA journal_mode = WAL;

DROP TABLE IF EXISTS gallery_fts;
DROP TABLE IF EXISTS gallery_tag;
DROP TABLE IF EXISTS gallery_thumb;
DROP TABLE IF EXISTS galleryproperty;
DROP TABLE IF EXISTS gallery;
DROP TABLE IF EXISTS feed;
DROP TABLE IF EXISTS image;
DROP TABLE IF EXISTS tag;
DROP TABLE IF EXISTS tagnamespace;

CREATE TABLE feed (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  term TEXT DEFAULT NULL,
  archived INTEGER NOT NULL DEFAULT 0,
  disabled INTEGER NOT NULL DEFAULT 0,
  download INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE gallery (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  exhenid INTEGER DEFAULT NULL,
  hash TEXT DEFAULT NULL,
  name TEXT DEFAULT NULL,
  added TEXT DEFAULT NULL,
  archived INTEGER DEFAULT 0,
  origtitle TEXT DEFAULT NULL,
  type TEXT DEFAULT NULL,
  posted TEXT DEFAULT NULL,
  numfiles INTEGER DEFAULT NULL,
  filesize INTEGER DEFAULT NULL,
  deleted INTEGER DEFAULT 0,
  lastaudit TEXT DEFAULT NULL,
  updated TEXT DEFAULT NULL,
  feed_id INTEGER DEFAULT NULL,
  hasmeta INTEGER DEFAULT 0,
  download INTEGER DEFAULT 1,
  source INTEGER DEFAULT 0,
  read INTEGER DEFAULT 0,
  color TEXT DEFAULT 'black'
);
CREATE UNIQUE INDEX gallery_exhenid ON gallery (exhenid);
CREATE INDEX gallery_feed ON gallery (feed_id);
CREATE INDEX gallery_posted ON gallery (posted);
CREATE INDEX gallery_added ON gallery (added);

CREATE TABLE tag (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT DEFAULT NULL
);
CREATE INDEX tag_name ON tag (name);

CREATE TABLE tagnamespace (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT DEFAULT NULL,
  similar_weight INTEGER DEFAULT 0
);
CREATE INDEX tagnamespace_name ON tagnamespace (name);

CREATE TABLE gallery_tag (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  gallery_id INTEGER DEFAULT NULL REFERENCES gallery (id) ON DELETE SET NULL ON UPDATE SET NULL,
  namespace_id INTEGER DEFAULT NULL REFERENCES tagnamespace (id) ON DELETE SET NULL ON UPDATE SET NULL,
  tag_id INTEGER DEFAULT NULL REFERENCES tag (id) ON DELETE SET NULL ON UPDATE SET NULL
);
CREATE INDEX gallery_tag_gallery ON gallery_tag (gallery_id);
CREATE INDEX gallery_tag_tag ON gallery_tag (tag_id);

CREATE TABLE image (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  width INTEGER DEFAULT NULL,
  height INTEGER DEFAULT NULL,
  type TEXT DEFAULT NULL,
  filename TEXT DEFAULT NULL
);

CREATE TABLE gallery_thumb (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  "index" INTEGER DEFAULT NULL,
  type INTEGER DEFAULT NULL,
  gallery_id INTEGER DEFAULT NULL REFERENCES gallery (id) ON DELETE SET NULL ON UPDATE SET NULL,
  image_id INTEGER DEFAULT NULL REFERENCES image (id) ON DELETE SET NULL ON UPDATE SET NULL
);
CREATE INDEX gallery_thumb_gallery ON gallery_thumb (gallery_id);
CREATE INDEX gallery_thumb_image ON gallery_thumb (image_id);

CREATE TABLE galleryproperty (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT DEFAULT NULL,
  value TEXT DEFAULT NULL,
  gallery_id INTEGER DEFAULT NULL REFERENCES gallery (id) ON DELETE SET NULL ON UPDATE SET NULL
);
CREATE INDEX galleryproperty_gallery ON galleryproperty (gallery_id);

-- Full text search (classes/Search.php), one row per gallery (rowid = gallery.id), kept up to date when a
-- gallery is stored. Filters and sorting use the gallery table. Contentless: the text is only in the
-- index, not stored a second time.
-- ":" belongs to words, so namespaced tags ("female:stockings") are one word; the parts of such words go
-- into "parts" ("female", "stockings"), like the blended words of the former Manticore index.
CREATE VIRTUAL TABLE gallery_fts USING fts5(
  name, origtitle, type, exhenid, tags, parts,
  content = '', contentless_delete = 1,
  tokenize = "unicode61 tokenchars ':_'"
);
