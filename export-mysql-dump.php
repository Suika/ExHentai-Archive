<?php
/**
 * The way back to MariaDB: writes the SQLite database as a MariaDB dump (mysqldump format) for the former
 * MariaDB + Manticore version of the app (master branch, db.sql there).
 *
 *   php export-mysql-dump.php dump.sql [source.sqlite]
 *   mysql -u exhen -p exhen < dump.sql
 *
 * Without a source the configured database is used (Docker: DB_PATH). Stop the app (or at least the tasks)
 * while exporting, so the dump is consistent. The dump replaces the tables of the target database; ids are
 * kept, so the images and archive folders stay valid. Search (Manticore) builds its index from the tables.
 * Docker: docker compose run --rm exhen php export-mysql-dump.php data/dump.sql
 */

if (php_sapi_name() !== 'cli') {
    exit;
}

chdir(__DIR__);
require 'common.php';

/** MariaDB schema of the former version (master db.sql), in mysqldump's table order */
const MYSQL_TABLES = array(
    'feed' => <<<'SQL'
CREATE TABLE `feed` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `term` varchar(45) DEFAULT NULL,
  `archived` tinyint(4) NOT NULL DEFAULT 0,
  `disabled` tinyint(4) NOT NULL DEFAULT 0,
  `download` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'gallery' => <<<'SQL'
CREATE TABLE `gallery` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `exhenid` int(11) unsigned DEFAULT NULL,
  `hash` varchar(255) DEFAULT NULL,
  `name` varchar(1024) DEFAULT NULL,
  `added` datetime DEFAULT NULL,
  `archived` tinyint(3) unsigned DEFAULT 0,
  `origtitle` varchar(1024) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `posted` datetime DEFAULT NULL,
  `numfiles` int(11) DEFAULT NULL,
  `filesize` bigint(20) unsigned DEFAULT NULL,
  `deleted` tinyint(4) DEFAULT 0,
  `lastaudit` datetime DEFAULT NULL,
  `updated` datetime DEFAULT NULL,
  `feed_id` int(11) unsigned DEFAULT NULL,
  `hasmeta` tinyint(4) DEFAULT 0,
  `download` tinyint(4) DEFAULT 1,
  `source` tinyint(4) DEFAULT 0,
  `read` tinyint(4) DEFAULT 0,
  `color` varchar(255) DEFAULT 'black',
  PRIMARY KEY (`id`),
  UNIQUE KEY `exhenid_UNIQUE` (`exhenid`),
  KEY `index_foreignkey_gallery_feed` (`feed_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'gallery_tag' => <<<'SQL'
CREATE TABLE `gallery_tag` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `gallery_id` int(11) unsigned DEFAULT NULL,
  `namespace_id` int(11) unsigned DEFAULT NULL,
  `tag_id` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_foreignkey_gallery_tag_tagnamespace` (`namespace_id`),
  KEY `index_foreignkey_gallery_tag_tag` (`tag_id`),
  KEY `index_foreignkey_gallery_tag_gallery` (`gallery_id`),
  CONSTRAINT `cons_fk_gallery_tag_gallery_id_id` FOREIGN KEY (`gallery_id`) REFERENCES `gallery` (`id`) ON DELETE SET NULL ON UPDATE SET NULL,
  CONSTRAINT `cons_fk_gallery_tag_namespace_id_id` FOREIGN KEY (`namespace_id`) REFERENCES `tagnamespace` (`id`) ON DELETE SET NULL ON UPDATE SET NULL,
  CONSTRAINT `cons_fk_gallery_tag_tag_id_id` FOREIGN KEY (`tag_id`) REFERENCES `tag` (`id`) ON DELETE SET NULL ON UPDATE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'gallery_thumb' => <<<'SQL'
CREATE TABLE `gallery_thumb` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `index` int(10) unsigned DEFAULT NULL,
  `type` tinyint(4) DEFAULT NULL,
  `gallery_id` int(11) unsigned DEFAULT NULL,
  `image_id` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_foreignkey_gallery_thumb_image` (`image_id`),
  KEY `index_foreignkey_gallery_thumb_gallery` (`gallery_id`),
  CONSTRAINT `cons_fk_gallery_thumb_gallery_id_id` FOREIGN KEY (`gallery_id`) REFERENCES `gallery` (`id`) ON DELETE SET NULL ON UPDATE SET NULL,
  CONSTRAINT `cons_fk_gallery_thumb_image_id_id` FOREIGN KEY (`image_id`) REFERENCES `image` (`id`) ON DELETE SET NULL ON UPDATE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'galleryproperty' => <<<'SQL'
CREATE TABLE `galleryproperty` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `value` varchar(255) DEFAULT NULL,
  `gallery_id` int(11) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `index_foreignkey_galleryproperty_gallery` (`gallery_id`),
  CONSTRAINT `cons_fk_galleryproperty_gallery_id_id` FOREIGN KEY (`gallery_id`) REFERENCES `gallery` (`id`) ON DELETE SET NULL ON UPDATE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'image' => <<<'SQL'
CREATE TABLE `image` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `width` int(11) unsigned DEFAULT NULL,
  `height` int(11) unsigned DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `filename` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'sph_counter' => <<<'SQL'
CREATE TABLE `sph_counter` (
  `counter_id` int(11) NOT NULL,
  `last_updated` datetime NOT NULL,
  PRIMARY KEY (`counter_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci
SQL,
    'tag' => <<<'SQL'
CREATE TABLE `tag` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    'tagnamespace' => <<<'SQL'
CREATE TABLE `tagnamespace` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `similar_weight` tinyint(4) DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
);

const ROWS_PER_INSERT = 1000;

if ($argc < 2) {
    fwrite(STDERR, "Usage: php export-mysql-dump.php <dump.sql> [source.sqlite]\n");
    exit(1);
}

if (isset($argv[2])) {
    if (!is_file($argv[2])) {
        fwrite(STDERR, "No such database: {$argv[2]}\n");
        exit(1);
    }
    R::addDatabase('export', 'sqlite:'.$argv[2]);
    R::selectDatabase('export');
}
$pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false); // numbers as numbers, to write them unquoted

$out = fopen($argv[1], 'wb');
if (!$out) {
    exit(1);
}

$start = microtime(true);

// one read transaction: a consistent snapshot, also if the app writes meanwhile
$pdo->beginTransaction();

$nextIds = $pdo->query('select name, seq + 1 from sqlite_sequence')->fetchAll(PDO::FETCH_KEY_PAIR);

fwrite($out, "-- ExHentai-Archive: SQLite database exported for MariaDB (export-mysql-dump.php, ".date('Y-m-d H:i:s').")\n\n");
fwrite($out, "/*!40101 SET NAMES utf8mb4 */;\n");
fwrite($out, "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE, TIME_ZONE='+00:00' */;\n");
fwrite($out, "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n");
fwrite($out, "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n");
fwrite($out, "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n");

$counts = array();
foreach (MYSQL_TABLES as $table => $create) {
    if (isset($nextIds[$table])) {
        $create = str_replace(') ENGINE=InnoDB', ') ENGINE=InnoDB AUTO_INCREMENT='.(int)$nextIds[$table], $create);
    }
    fwrite($out, "--\n-- Table structure for table `$table`\n--\n\n");
    fwrite($out, "DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

    if ($table === 'sph_counter') {
        continue; // Manticore's bookkeeping: filled by the first indexing
    }

    // columns in the order of the MariaDB table (the SQLite tables have the same columns)
    preg_match_all('/^\s+`(\w+)`/m', $create, $m);
    $columns = $m[1];

    fwrite($out, "--\n-- Dumping data for table `$table`\n--\n\n");
    fwrite($out, "LOCK TABLES `$table` WRITE;\n/*!40000 ALTER TABLE `$table` DISABLE KEYS */;\n");

    $rows = $pdo->query('select '.implode(', ', array_map(fn ($c) => '"'.$c.'"', $columns))." from $table order by id", PDO::FETCH_NUM);
    $batch = array();
    $counts[$table] = 0;
    foreach ($rows as $row) {
        $batch[] = '('.implode(',', array_map('sqlValue', $row)).')';
        if (count($batch) === ROWS_PER_INSERT) {
            fwrite($out, "INSERT INTO `$table` VALUES ".implode(',', $batch).";\n");
            $batch = array();
        }
        $counts[$table]++;
    }
    if ($batch) {
        fwrite($out, "INSERT INTO `$table` VALUES ".implode(',', $batch).";\n");
    }

    fwrite($out, "/*!40000 ALTER TABLE `$table` ENABLE KEYS */;\nUNLOCK TABLES;\n\n");
    printf("%-16s %10d rows\n", $table, $counts[$table]);
}

$pdo->commit();

fwrite($out, "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n");
fwrite($out, "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n");
fwrite($out, "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n");
fwrite($out, "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n\n");
fwrite($out, "-- Dump completed\n");
fclose($out);

printf("Done in %d s: %s\n", microtime(true) - $start, $argv[1]);

/**
 * A value as MariaDB literal: NULL, number, or quoted string with mysqldump's escapes.
 */
function sqlValue($value)
{
    if ($value === null) {
        return 'NULL';
    }
    if (is_int($value) || is_float($value)) {
        return (string)$value;
    }

    return "'".strtr($value, array(
        '\\' => '\\\\', "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "'" => "\\'", '"' => '\\"', "\x1a" => '\\Z',
    ))."'";
}
