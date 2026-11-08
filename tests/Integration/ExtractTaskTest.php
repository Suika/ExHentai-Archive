<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * The Extract task: gallery zips converted to tar files.
 */
final class ExtractTaskTest extends TestCase
{
    private static function tarFiles(string $tar): array
    {
        $files = array();
        $phar = new \PharData($tar);
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $name = substr($file->getPathname(), strlen('phar://'.$tar.'/'));
            $files[$name] = file_get_contents($file->getPathname());
        }
        ksort($files);

        return $files;
    }

    private static function zipFiles(string $zip): array
    {
        $files = array();
        $archive = new \ZipArchive();
        $archive->open($zip);
        for ($i = 0; $i < $archive->numFiles; $i++) {
            $name = $archive->getNameIndex($i);
            if (substr($name, -1) !== '/') {
                $files[$name] = $archive->getFromIndex($i);
            }
        }
        ksort($files);

        return $files;
    }

    public function testConvertsAllGalleries(): void
    {
        Stack::reset();
        $ids = Stack::seedExhentaiGalleries();
        $manual = Stack::seedManualGallery('Manual Extract', ZipFactory::standardEntries(3));

        $zip64 = APP_ROOT.'/tmp/zip64.zip';
        copy(APP_ROOT.'/tests/fixtures/archives/zip64.zip', $zip64);
        Stack::task('AddGallery', $zip64, 'Zip64 Extract');
        $zip64Id = (int)Db::value('select id from gallery where name = ?', array('Zip64 Extract'));

        $result = Stack::task('Extract');

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('Converted 4 galleries (0 failed)', $result['output']);

        $expected = array(
            APP_ROOT.'/archive/archives/3650000/3654969.tar' => APP_ROOT.'/archive/galleries/3654969.zip',
            APP_ROOT.'/archive/archives/4300000/4302753.tar' => APP_ROOT.'/archive/galleries/4302753.zip',
            APP_ROOT.'/archive/archives/0/1-'.$manual.'.tar' => APP_ROOT.'/archive/galleries/1-'.$manual.'.zip',
            APP_ROOT.'/archive/archives/0/1-'.$zip64Id.'.tar' => APP_ROOT.'/archive/galleries/1-'.$zip64Id.'.zip',
        );
        foreach ($expected as $tar => $zip) {
            $this->assertFileExists($tar);
            $this->assertFileExists($zip, 'the zip is kept');
            $this->assertSame(self::zipFiles($zip), self::tarFiles($tar), basename($tar).' has the same pages');
        }
        $this->assertSame(array(), glob(APP_ROOT.'/archive/archives/*/*.tmp.tar'), 'no temporary files left');
        $this->assertArrayHasKey(3654969, $ids);
    }

    public function testSingleGalleryAndMissingZip(): void
    {
        Stack::reset();
        $id = Stack::seedManualGallery('Single', ZipFactory::standardEntries());
        $missing = Stack::seedManualGallery('Missing Zip', ZipFactory::standardEntries());
        unlink(APP_ROOT.'/archive/galleries/1-'.$missing.'.zip');

        $one = Stack::task('Extract', (string)$id);
        $this->assertStringContainsString('Converted 1 galleries (0 failed)', $one['output']);
        $this->assertFileExists(APP_ROOT.'/archive/archives/0/1-'.$id.'.tar');

        $all = Stack::task('Extract');
        $this->assertStringContainsString("Zip file doesn't exist", $all['output']);
        $this->assertStringContainsString('Converted 1 galleries (1 failed)', $all['output'], 'continues after a missing zip');

        $this->assertStringContainsString('Gallery not found: 999999', Stack::task('Extract', '999999')['output']);
    }
}
