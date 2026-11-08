<?php

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * Changing API calls: queueing galleries (userscript), read status, colour, delete, cache flush.
 */
final class ApiWriteTest extends TestCase
{
    private const KEY = 'test-key'; // EX_ACCESSKEY in docker-compose.test.yml

    private int $gallery;

    protected function setUp(): void
    {
        Stack::reset();
        $this->gallery = Stack::seedManualGallery('Write Test Gallery', ZipFactory::standardEntries(), array('female:glasses'));
        Stack::reindex();
    }

    public function testAddGalleryQueuesADownload(): void
    {
        $data = Api::data('addgallery', array('gid' => 111, 'token' => 'aaaaaaaaaa'));

        $this->assertSame('111', (string)$data['exhenid']);
        $row = Db::galleryByExhenid(111);
        $this->assertSame(array('aaaaaaaaaa', '1', '0', '0'),
            array($row['hash'], (string)$row['download'], (string)$row['archived'], (string)$row['hasmeta']));

        $this->assertSame(array('exists' => true, 'id' => (string)$row['id'], 'archived' => false, 'deleted' => '0'),
            Api::data('hasgallery', array('gid' => 111)));
    }

    public function testAddGalleryAgainReenablesTheDownload(): void
    {
        $id = Api::data('addgallery', array('gid' => 111, 'token' => 'aaaaaaaaaa'))['id'];
        Db::exec('update gallery set download = 0 where id = ?', array($id));

        $again = Api::data('addgallery', array('gid' => 111, 'token' => 'aaaaaaaaaa'));

        $this->assertSame((string)$id, (string)$again['id'], 'no second row');
        $this->assertSame('1', (string)Db::gallery((int)$id)['download']);
        $this->assertSame(1, Db::count('gallery', 'exhenid = 111'));
    }

    public function testAddGalleryNeedsGidAndToken(): void
    {
        $this->assertSame(array('ret' => false, 'message' => 'Invalid params'), Api::call('addgallery', array('gid' => 1)));
    }

    public function testUpdateReadStatus(): void
    {
        Api::data('galleries'); // cache the list entry

        $this->assertSame('1', (string)Api::data('update', array('id' => $this->gallery, 'readStatus' => 1)));

        $this->assertSame('1', (string)Api::data('gallery', array('id' => $this->gallery))['read']);
        $listed = Api::data('galleries')['galleries'][0];
        $this->assertSame('1', (string)$listed['read'], 'cached list entry was dropped');
    }

    public function testUpdateColor(): void
    {
        Api::data('galleries');

        $this->assertSame('blue', Api::data('updateColor', array('id' => $this->gallery, 'color' => 'blue')));

        $this->assertSame('blue', Api::data('gallery', array('id' => $this->gallery))['color']);
        $this->assertSame('blue', Api::data('galleries')['galleries'][0]['color']);
    }

    public function testDeleteNeedsTheAccessKey(): void
    {
        $this->assertSame('Invalid access key',
            Api::call('deletegallery', array('id' => $this->gallery, 'key' => 'wrong'))['message']);
        $this->assertSame('0', (string)Db::gallery($this->gallery)['deleted']);
    }

    public function testDeleteMarksTheGallery(): void
    {
        $this->assertTrue(Api::data('deletegallery', array('id' => $this->gallery, 'key' => self::KEY)));
        $this->assertSame('1', (string)Db::gallery($this->gallery)['deleted'], 'the Cleanup task removes it later');

        $this->assertSame('Gallery not found or already deleted',
            Api::call('deletegallery', array('id' => $this->gallery, 'key' => self::KEY))['message']);
    }

    public function testFlush(): void
    {
        $this->assertSame('Cache flushed', Api::data('flush'));
    }

    #[Group('known-bug')]
    public function testUnknownGalleryIsNotCreated(): void
    {
        // B8: R::load() returns an empty bean for unknown ids, which is then stored as a new gallery
        $before = Db::count('gallery');

        $responses = array(
            'update' => Api::call('update', array('id' => 999999, 'readStatus' => 1)),
            'updateColor' => Api::call('updateColor', array('id' => 999999, 'color' => 'red')),
            'deletegallery' => Api::call('deletegallery', array('id' => 999999, 'key' => self::KEY)),
            'gallery' => Api::call('gallery', array('id' => 999999)),
        );

        $this->assertSame($before, Db::count('gallery'), 'no gallery rows were created');
        foreach ($responses as $action => $response) {
            $this->assertFalse($response['ret'], $action);
        }
    }

    #[Group('known-bug')]
    public function testReadFilterFollowsUpdateWithoutManualReindex(): void
    {
        // B9: the indexer started by the request (www-data) can't make the root searchd load the new index
        Api::data('update', array('id' => $this->gallery, 'readStatus' => 1));

        $ids = array();
        for ($i = 0; $i < 30; $i++) {
            $ids = array_map(fn ($g) => (int)$g['id'], Api::data('galleries', array('read' => 1))['galleries']);
            if ($ids) {
                break;
            }
            usleep(100000);
        }

        $this->assertSame(array($this->gallery), $ids);
    }
}
