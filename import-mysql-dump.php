<?php
/**
 * Migration from MariaDB: imports a dump of the former MariaDB database into the SQLite database
 * and builds the search index.
 *
 *   mysqldump -h <host> -u <user> -p exhen > dump.sql        (a dump made with HeidiSQL/DBeaver works too)
 *   php import-mysql-dump.php dump.sql [target.sqlite]
 *
 * Without a target the configured database is used (Docker: DB_PATH, created by the entry point).
 * The target must not contain galleries yet. Ids are kept, so the images and archive folders stay valid.
 * Docker: docker compose run --rm exhen php import-mysql-dump.php data/dump.sql
 */

if (php_sapi_name() !== 'cli') {
    exit;
}

chdir(__DIR__);
require 'common.php';

const TABLES = array('feed', 'gallery', 'tag', 'tagnamespace', 'gallery_tag', 'image', 'gallery_thumb', 'galleryproperty');

if ($argc < 2 || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php import-mysql-dump.php <dump.sql> [target.sqlite]\n");
    exit(1);
}

if (isset($argv[2])) {
    R::addDatabase('import', 'sqlite:'.$argv[2]);
    R::selectDatabase('import');
}
$pdo = R::getDatabaseAdapter()->getDatabase()->getPDO();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$hasSchema = (bool)$pdo->query("select count(*) from sqlite_master where name = 'gallery'")->fetchColumn();
if (!$hasSchema) {
    $pdo->exec(file_get_contents(__DIR__.'/db.sql'));
} elseif ($pdo->query('select count(*) from gallery')->fetchColumn() > 0) {
    fwrite(STDERR, "The target database already contains galleries, nothing imported.\n");
    exit(1);
}

$pdo->exec('PRAGMA foreign_keys = OFF');   // the dump's table order doesn't follow the references
$pdo->exec('PRAGMA synchronous = OFF');
$pdo->exec('PRAGMA cache_size = -200000'); // 200 MB

$start = microtime(true);
$in = fopen($argv[1], 'rb');

$table = null;       // CREATE TABLE being read
$columns = array();  // table => column names in dump order
$nextIds = array();  // table => AUTO_INCREMENT
$counts = array();
$statements = array();

$pdo->beginTransaction();

while (($line = fgets($in)) !== false) {
    if (preg_match('/^CREATE TABLE `(\w+)`/', $line, $m)) {
        $table = $m[1];
        $columns[$table] = array();
        continue;
    }

    if ($table !== null) {
        if (preg_match('/^\s+`(\w+)`/', $line, $m)) {
            $columns[$table][] = $m[1];
        } elseif (preg_match('/^\)/', $line)) {
            if (preg_match('/AUTO_INCREMENT=(\d+)/', $line, $m)) {
                $nextIds[$table] = (int)$m[1];
            }
            $table = null;
        }
        continue;
    }

    if (!preg_match('/^INSERT INTO `(\w+)` VALUES /', $line, $m)) {
        continue;
    }
    $insertTable = $m[1];
    if (!in_array($insertTable, TABLES, true)) {
        continue;
    }

    if (!isset($statements[$insertTable])) {
        $cols = $columns[$insertTable];
        $statements[$insertTable] = $pdo->prepare(sprintf(
            'insert into %s (%s) values (%s)',
            $insertTable,
            implode(', ', array_map(fn ($c) => '"'.$c.'"', $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        ));
        $counts[$insertTable] = 0;
        echo "Importing $insertTable\n";
    }

    $counts[$insertTable] += insertRows($statements[$insertTable], substr($line, strlen($m[0])), count($columns[$insertTable]));
}
fclose($in);

// keep the id counters of the dump, so ids of removed galleries are not given out again
foreach ($nextIds as $name => $next) {
    if (in_array($name, TABLES, true)) {
        $pdo->prepare('update sqlite_sequence set seq = max(seq, ?) where name = ?')->execute(array($next - 1, $name));
    }
}

$pdo->commit();
$pdo->exec('PRAGMA foreign_keys = ON');

foreach (TABLES as $name) {
    printf("%-16s %10d rows\n", $name, $counts[$name] ?? 0);
}

echo "Building the search index\n";
printf("%d galleries indexed\n", Search::rebuild());

$pdo->exec('PRAGMA synchronous = FULL');
$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
$pdo->exec('ANALYZE');

printf("Done in %d s\n", microtime(true) - $start);

/**
 * Inserts the rows of one extended INSERT: "(1,'a\'b',NULL),(2,'c',3);"
 *
 * @return int number of rows
 */
function insertRows(PDOStatement $statement, $values, $columnCount)
{
    // tokens: brackets, quoted strings (MySQL backslash escapes) and unquoted values (numbers, NULL)
    preg_match_all("/[()]|'[^'\\\\]*(?:\\\\.[^'\\\\]*)*'|[^,()';\\s]+/s", $values, $tokens);

    $rows = 0;
    $row = null;
    foreach ($tokens[0] as $token) {
        if ($token === '(') {
            $row = array();
        } elseif ($token === ')') {
            if (count($row) !== $columnCount) {
                throw new RuntimeException('Unexpected number of values in a row: '.json_encode($row));
            }
            $statement->execute($row);
            $rows++;
            $row = null;
        } elseif ($token[0] === "'") {
            $row[] = strtr(substr($token, 1, -1), array(
                '\\0' => "\0", "\\'" => "'", '\\"' => '"', '\\b' => "\x08", '\\n' => "\n",
                '\\r' => "\r", '\\t' => "\t", '\\Z' => "\x1a", '\\\\' => '\\',
            ));
        } elseif ($token === 'NULL') {
            $row[] = null;
        } else {
            $row[] = $token;
        }
    }

    return $rows;
}
