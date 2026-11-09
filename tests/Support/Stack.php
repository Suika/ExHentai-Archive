<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * The running app inside the test container: data reset, CLI tasks and test data.
 */
final class Stack
{
    /** ExHentai galleries with a saved page in tests/fixtures/exhentai: gid => token */
    public const GALLERIES = array(3654969 => 'c55b7392ac', 4302753 => '6b515751d9');

    private static ?FakeExhentai $fake = null;

    public static function fake(): FakeExhentai
    {
        return self::$fake ??= new FakeExhentai();
    }

    /**
     * Empty database, archive, images, cache, search index and fake ExHentai state.
     */
    public static function reset(): void
    {
        Db::reset();

        foreach (array('archive', 'images', 'tmp') as $dir) {
            self::emptyDir(APP_ROOT.'/'.$dir);
        }

        self::flushCache();
        self::fake()->reset();
    }

    public static function flushCache(): void
    {
        $memcached = new \Memcached();
        $memcached->addServer('127.0.0.1', 11211);
        $memcached->flush();
    }

    /**
     * Rebuilds the search index, for rows that a test changed directly in the database (the app keeps it
     * up to date itself when it stores galleries).
     */
    public static function reindex(): void
    {
        exec('cd '.escapeshellarg(APP_ROOT).' && php -r '.escapeshellarg('require "common.php"; Search::rebuild();').' 2>&1', $out, $ret);
        Assert::assertSame(0, $ret, "Rebuilding the search index failed:\n".implode("\n", $out));
    }

    /**
     * Runs "php TaskRunner.php <task> <args...>" and returns its exit code and output.
     *
     * @return array{code:int, output:string}
     */
    public static function task(string $task, string ...$args): array
    {
        $cmd = 'cd '.escapeshellarg(APP_ROOT).' && php TaskRunner.php '.escapeshellarg($task);
        foreach ($args as $arg) {
            $cmd .= ' '.escapeshellarg($arg);
        }
        exec($cmd.' 2>&1', $out, $ret);

        return array('code' => $ret, 'output' => implode("\n", $out));
    }

    /**
     * Adds the saved ExHentai galleries the way the app does: queued through the API (userscript) and
     * downloaded by the Archive task from the fake ExHentai server. Every gallery gets the standard archive
     * (ZipFactory::standardEntries).
     *
     * @return array<int,int> exhenid => gallery id
     */
    public static function seedExhentaiGalleries(): array
    {
        $fake = self::fake();
        $seed = 0;
        foreach (self::GALLERIES as $gid => $token) {
            Fixtures::html("gallery-$gid.html");
            $fake->gallery($gid, "gallery-$gid.html");
            $fake->archive($gid, ZipFactory::standardEntries($seed += 10));
            Api::data('addgallery', array('gid' => $gid, 'token' => $token));
        }

        $result = self::task('Archive');
        Assert::assertSame(0, $result['code'], $result['output']);

        $ids = array();
        foreach (self::GALLERIES as $gid => $token) {
            $row = Db::galleryByExhenid($gid);
            Assert::assertNotNull($row, "Gallery $gid was not created:\n".$result['output']);
            Assert::assertSame('1', (string)$row['archived'], "Gallery $gid was not archived:\n".$result['output']);
            $ids[$gid] = (int)$row['id'];
        }

        return $ids;
    }

    /**
     * Adds a gallery from a local zip with the AddGallery task and tags it with EditGallery.
     *
     * @param string[] $tags "namespace:tag"
     * @return int gallery id
     */
    public static function seedManualGallery(string $name, array $entries, array $tags = array()): int
    {
        $zip = ZipFactory::create(APP_ROOT.'/tmp/upload-'.md5($name).'.zip', $entries);

        $result = self::task('AddGallery', $zip, $name);
        Assert::assertSame(0, $result['code'], $result['output']);

        $id = (int)Db::value('select id from gallery where name = ? order by id desc', array($name));
        Assert::assertGreaterThan(0, $id, "AddGallery did not create the gallery:\n".$result['output']);

        if ($tags) {
            $result = self::task('EditGallery', (string)$id, 'addtag', ...$tags);
            Assert::assertSame(0, $result['code'], $result['output']);
        }

        unlink($zip);

        return $id;
    }

    /**
     * Changes values in the "base" section of the app's config.json (the generated one and the public
     * www/config.json) and returns a function that restores both.
     */
    public static function config(array $values): callable
    {
        $files = array(APP_ROOT.'/config.json', APP_ROOT.'/www/config.json');
        $saved = array();
        foreach ($files as $file) {
            $saved[$file] = file_get_contents($file);
            $data = json_decode($saved[$file], true);
            foreach ($values as $key => $value) {
                $data['base'][$key] = $value;
            }
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        return function () use ($saved) {
            foreach ($saved as $file => $contents) {
                file_put_contents($file, $contents);
            }
        };
    }

    public static function archivePath(string $relative): string
    {
        return APP_ROOT.'/archive/'.$relative;
    }

    private static function emptyDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }
}
