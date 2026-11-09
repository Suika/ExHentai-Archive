<?php

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * import-mysql-dump.php: a MariaDB dump (mysqldump format) into a new SQLite database, with search index.
 */
final class MigrationTest extends TestCase
{
    private const TARGET = APP_ROOT.'/tmp/migration-test.sqlite';

    protected function setUp(): void
    {
        foreach (glob(self::TARGET.'*') as $file) {
            unlink($file);
        }
    }

    private static function import(): array
    {
        $cmd = sprintf('cd %s && php import-mysql-dump.php tests/fixtures/mysql/dump.sql %s 2>&1',
            escapeshellarg(APP_ROOT), escapeshellarg(self::TARGET));
        exec($cmd, $out, $ret);

        return array($ret, implode("\n", $out));
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

    public function testRefusesADatabaseWithGalleries(): void
    {
        $this->assertSame(0, self::import()[0]);

        list($ret, $output) = self::import();

        $this->assertSame(1, $ret);
        $this->assertStringContainsString('already contains galleries', $output);
    }
}
