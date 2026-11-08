<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * The Thumbnails task: small thumbnails of every page (reader strip) and the list cover.
 */
final class ThumbnailsTaskTest extends TestCase
{
    public function testCreatesThumbnailsAndCoverOnce(): void
    {
        Stack::reset();
        $id = Stack::seedManualGallery('Thumb Gallery', ZipFactory::standardEntries());
        $pages = count(ZipFactory::pageOrder());

        $result = Stack::task('Thumbnails');
        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('Created 1 covers (0 failed)', $result['output']);

        $this->assertSame($pages, Db::count('gallery_thumb', 'gallery_id = ? and type = 2', array($id)));
        $this->assertSame(1, Db::count('gallery_thumb', 'gallery_id = ? and type = 1 and `index` = 0', array($id)));
        $images = glob(APP_ROOT.'/images/*');
        $this->assertCount($pages + 1, $images);

        $small = Api::request('gallerythumb', array('id' => $id, 'index' => 4, 'type' => 2)); // 10.jpg 640x900
        $info = getimagesizefromstring(Api::get($small['headers']['location'])['body']);
        $this->assertSame(array(140, 197), array($info[0], $info[1]));

        // the API now finds them instead of creating new ones
        Api::request('gallerythumb', array('id' => $id, 'index' => 0, 'type' => 1));
        $this->assertCount($pages + 1, glob(APP_ROOT.'/images/*'));

        $second = Stack::task('Thumbnails');
        $this->assertStringContainsString('Created 0 covers (0 failed)', $second['output']);
        $this->assertCount($pages + 1, glob(APP_ROOT.'/images/*'), 'a second run creates nothing new');
        $this->assertSame($pages + 1, Db::count('gallery_thumb'));
    }

    public function testCorrectsPageCountOfOlderGalleries(): void
    {
        Stack::reset();
        $id = Stack::seedManualGallery('Old Count', ZipFactory::standardEntries());
        // stored by an older version: the "pages/" directory entry counted as a page
        Db::exec('update gallery set numfiles = 6 where id = ?', array($id));

        $result = Stack::task('Thumbnails');

        $this->assertStringContainsString('has 5 pages, not 6', $result['output']);
        $this->assertSame('5', (string)Db::gallery($id)['numfiles']);
        $this->assertSame('5', (string)Api::data('gallery', array('id' => $id))['numfiles']);
    }

    public function testCoverFailureIsReported(): void
    {
        Stack::reset();
        $id = Stack::seedManualGallery('Broken Gallery', ZipFactory::standardEntries());
        unlink(APP_ROOT.'/archive/galleries/1-'.$id.'.zip');

        $result = Stack::task('Thumbnails');

        $this->assertSame(0, $result['code'], $result['output']);
        $this->assertStringContainsString('Created 0 covers (1 failed)', $result['output']);
    }
}
