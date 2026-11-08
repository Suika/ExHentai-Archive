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
                sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('DB_HOST'), getenv('DB_NAME')),
                getenv('DB_USER'),
                getenv('DB_PASS'),
                array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
            );
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
        $cmd = sprintf('mariadb --skip-ssl -h %s -u %s -p%s < %s 2>&1',
            escapeshellarg(getenv('DB_HOST')), escapeshellarg(getenv('DB_USER')),
            escapeshellarg(getenv('DB_PASS')), escapeshellarg(APP_ROOT.'/db.sql'));
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            throw new \RuntimeException("Loading db.sql failed:\n".implode("\n", $out));
        }
    }
}
