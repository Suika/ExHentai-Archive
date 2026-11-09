<?php

namespace Tests\Support;

use PDO;

/**
 * Direct database access for the few things the API doesn't expose (lastaudit, hasmeta, download, ...).
 * Keep SQL in tests to this helper so a database change (D29) only touches this file.
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            self::$pdo = new PDO(
                'sqlite:'.getenv('DB_PATH'),
                null,
                null,
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_STRINGIFY_FETCHES => true) // values as strings, like the app (RedBean) gets them
            );
            self::$pdo->exec('PRAGMA busy_timeout = 10000');
        }

        return self::$pdo;
    }

    public static function all(string $sql, array $params = array()): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function row(string $sql, array $params = array()): ?array
    {
        return self::all($sql, $params)[0] ?? null;
    }

    public static function value(string $sql, array $params = array())
    {
        $row = self::row($sql, $params);
        return $row ? reset($row) : null;
    }

    public static function exec(string $sql, array $params = array()): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function gallery(int $id): ?array
    {
        return self::row('select * from gallery where id = ?', array($id));
    }

    public static function galleryByExhenid(int $exhenid): ?array
    {
        return self::row('select * from gallery where exhenid = ?', array($exhenid));
    }

    public static function count(string $table, string $where = '1', array $params = array()): int
    {
        return (int)self::value("select count(*) from `$table` where $where", $params);
    }

    /** Recreates the schema from db.sql */
    public static function reset(): void
    {
        self::$pdo = null;
        $cmd = sprintf('sqlite3 %s < %s 2>&1', escapeshellarg(getenv('DB_PATH')), escapeshellarg(APP_ROOT.'/db.sql'));
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            throw new \RuntimeException("Loading db.sql failed:\n".implode("\n", $out));
        }
    }
}
