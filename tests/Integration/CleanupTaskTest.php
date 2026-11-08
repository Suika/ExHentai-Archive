<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * The Cleanup task: removes galleries marked as deleted (files and rows) and orphaned rows.
 */
final class CleanupTaskTest extends TestCase
{
    public function testRemovesDeletedGalleries(): void
    {
        Stack::reset();

        $ids = Stack::seedExhentaiGalleries();
        $feedGallery = $ids[4302753];
        $keep = $ids[3654969];
        $manual = Stack::seedManualGallery('Manual To Delete', ZipFactory::standardEntries(), array('female:glasses'));

        Db::exec("insert into feed (term) values ('test')");
        Db::exec('update gallery set feed_id = 1 where id = ?', array($feedGallery));
        $this->assertSame(0, Stack::task('Thumbnails')['code']);

        $files = array(
            'manual zip' => APP_ROOT.'/archive/galleries/1-'.$manual.'.zip',
            'feed zip' => APP_ROOT.'/archive/galleries/4302753.zip',
            'feed page' => APP_ROOT.'/archive/pages/4302753.html',
        );
        $thumbFiles = array();
        foreach (Db::all('select i.id, i.type from image i join gallery_thumb t on t.image_id = i.id where t.gallery_id in (?, ?)',
            array($manual, $feedGallery)) as $image) {
            $thumbFiles[] = sprintf('%s/images/%06d.%s', APP_ROOT, $image['id'], $image['type']);
        }
        foreach (array_merge($files, $thumbFiles) as $file) {
            $this->assertFileExists($file);
        }

        foreach (array($manual, $feedGallery) as $id) {
            Api::data('deletegallery', array('id' => $id, 'key' => 'test-key'));
        }
        // orphans left behind by older versions
        Db::exec('insert into galleryproperty (name, value, gallery_id) values (?, ?, null)', array('x', 'y'));
        Db::exec('insert into gallery_tag (gallery_id, namespace_id, tag_id) values (null, 1, 1)');

        $result = Stack::task('Cleanup');
        $this->assertSame(0, $result['code'], $result['output']);

        foreach (array_merge($files, $thumbFiles) as $name => $file) {
            $this->assertFileDoesNotExist($file, (string)$name);
        }

        $this->assertNull(Db::gallery($manual), 'galleries not from a feed are removed');
        $this->assertSame('2', (string)Db::gallery($feedGallery)['deleted'], 'feed galleries stay as deleted=2 (not re-added)');
        foreach (array('galleryproperty', 'gallery_tag', 'gallery_thumb') as $table) {
            $this->assertSame(0, Db::count($table, 'gallery_id in (?, ?) or gallery_id is null', array($manual, $feedGallery)), $table);
        }
        $this->assertSame(0, Db::count('image', 'id not in (select image_id from gallery_thumb)'), 'no orphaned images');

        // the other gallery is untouched
        $this->assertSame('0', (string)Db::gallery($keep)['deleted']);
        $this->assertFileExists(APP_ROOT.'/archive/galleries/3654969.zip');
        $this->assertGreaterThan(0, Db::count('gallery_thumb', 'gallery_id = ?', array($keep)));
    }
}
