<?php

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * import-mysql-dump.php: a MariaDB dump (mysqldump format) into a new SQLite database, with search index.
 * export-mysql-dump.php: the way back, SQLite into a MariaDB dump.
 */
final class MigrationTest extends TestCase
{
    private const TARGET = APP_ROOT.'/tmp/migration-test.sqlite';
    private const EXPORT = APP_ROOT.'/tmp/migration-test-export.sql';
    private const ROUNDTRIP = APP_ROOT.'/tmp/migration-test-roundtrip.sqlite';

    protected function setUp(): void
    {
        foreach (array_merge(glob(self::TARGET.'*'), glob(self::ROUNDTRIP.'*'), glob(self::EXPORT)) as $file) {
            unlink($file);
        }
    }

    private static function php(string $script, string ...$args): array
    {
        $cmd = 'cd '.escapeshellarg(APP_ROOT).' && php '.$script;
        foreach ($args as $arg) {
            $cmd .= ' '.escapeshellarg($arg);
        }
        exec($cmd.' 2>&1', $out, $ret);

        return array($ret, implode("\n", $out));
    }

    private static function import(string $dump = 'tests/fixtures/mysql/dump.sql', string $target = self::TARGET): array
    {
        return self::php('import-mysql-dump.php', $dump, $target);
    }

    /** all rows of all tables and the next ids */
    private static function contents(string $file): array
    {
        $db = new PDO('sqlite:'.$file, null, null, array(PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
        $data = array();
        foreach (array('feed', 'gallery', 'tag', 'tagnamespace', 'gallery_tag', 'image', 'gallery_thumb', 'galleryproperty', 'sqlite_sequence') as $table) {
            $data[$table] = $db->query("select * from $table order by 1")->fetchAll();
        }
        $data['search'] = $db->query("select rowid from gallery_fts where gallery_fts match '\"female:big breasts\" AND \"東 方\"'")->fetchAll();

        return $data;
    }

    public function testImport(): void
    {
        list($ret, $output) = self::import();
        $this->assertSame(0, $ret, $output);
        $this->assertMatchesRegularExpression('/gallery_tag\s+3 rows/', $output);

        $db = new PDO('sqlite:'.self::TARGET, null, null, array(PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));

        $gallery = $db->query('select * from gallery where id = 10')->fetch();
        $this->assertSame('[Circle] It\'s a "Test" Book\\Part (C99) [English]', $gallery['name'], 'escapes');
        $this->assertSame('[サークル] 東方テスト本', $gallery['origtitle'], 'UTF-8');
        $this->assertSame(array(111, 123456789, 1, 'red', '2025-12-31 23:59:00'),
            array($gallery['exhenid'], $gallery['filesize'], $gallery['read'], $gallery['color'], $gallery['posted']));
        $this->assertNull($gallery['lastaudit']);

        $other = $db->query('select * from gallery where id = 12')->fetch();
        $this->assertSame(array(null, "Line\nBreak and\ttab"), array($other['exhenid'], $other['name']));

        $this->assertSame('artist:"some artist$" eng', $db->query('select term from feed where id = 1')->fetchColumn());
        $this->assertSame(array(null), $db->query('select gallery_id from gallery_tag where id = 3')->fetchAll(PDO::FETCH_COLUMN));
        $this->assertSame(0, (int)$db->query("select count(*) from sqlite_master where name = 'sph_counter'")->fetchColumn());
        $this->assertSame(49, (int)$db->query("select seq from sqlite_sequence where name = 'gallery'")->fetchColumn(),
            'the next gallery id continues after the dump (AUTO_INCREMENT=50)');

        $search = fn ($match) => $db->query("select rowid from gallery_fts where gallery_fts match '$match'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(array(10), $search('"female:big breasts"'));
        $this->assertSame(array(10), $search('"東 方"'));
        $this->assertSame(array(12), $search('"line"'));
    }

    public function testExportImportsBackUnchanged(): void
    {
        $this->assertSame(0, self::import()[0]);

        list($ret, $output) = self::php('export-mysql-dump.php', self::EXPORT, self::TARGET);
        $this->assertSame(0, $ret, $output);
        $this->assertMatchesRegularExpression('/gallery\s+2 rows/', $output);

        $dump = file_get_contents(self::EXPORT);
        $this->assertStringContainsString("'[Circle] It\\'s a \\\"Test\\\" Book\\\\Part (C99) [English]'", $dump, 'mysqldump escapes');
        $this->assertStringContainsString("'Line\\nBreak and\ttab'", $dump);
        $this->assertStringContainsString('ENGINE=InnoDB AUTO_INCREMENT=50 ', $dump, 'next gallery id');
        $this->assertStringContainsString('CREATE TABLE `sph_counter`', $dump, 'the former schema, for Manticore');

        list($ret, $output) = self::import('tmp/'.basename(self::EXPORT), self::ROUNDTRIP);
        $this->assertSame(0, $ret, $output);
        $this->assertSame(self::contents(self::TARGET), self::contents(self::ROUNDTRIP));
        $this->assertNotEmpty(self::contents(self::ROUNDTRIP)['search']);
    }

    public function testRefusesADatabaseWithGalleries(): void
    {
        $this->assertSame(0, self::import()[0]);

        list($ret, $output) = self::import();

        $this->assertSame(1, $ret);
        $this->assertStringContainsString('already contains galleries', $output);
    }
}
