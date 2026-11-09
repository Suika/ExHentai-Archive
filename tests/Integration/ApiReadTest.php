<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Snapshot;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * Read side of the API (gallery list, search, filters, single gallery, suggestions, stats) as the
 * frontend and the userscript use it. Data is created through the app itself (API, Archive, AddGallery,
 * EditGallery), only the dates are set directly so the order is deterministic.
 */
final class ApiReadTest extends TestCase
{
    /** ExHentai galleries (fixtures), posted 2025-11-25 and 2026-11-03 */
    private static int $rotten;   // 3654969 "Rotten Petal", tags incl. male:glasses
    private static int $pixiv;    // 4302753 "[Momomimi] Pixiv", tags incl. female:stockings, male:tomgirl
    /** manual galleries (AddGallery) */
    private static int $manual;   // female:glasses, artist:tester
    private static int $another;  // female:stockings, artist:writer
    private static int $unarchived;

    public static function setUpBeforeClass(): void
    {
        Stack::reset();

        $ids = Stack::seedExhentaiGalleries();
        self::$rotten = $ids[3654969];
        self::$pixiv = $ids[4302753];
        self::$manual = Stack::seedManualGallery('Manual Test Gallery', ZipFactory::standardEntries(50),
            array('female:glasses', 'artist:tester'));
        self::$another = Stack::seedManualGallery('Another Book', ZipFactory::standardEntries(60),
            array('female:stockings', 'artist:writer'));

        // queued from the userscript but not downloaded yet
        self::$unarchived = (int)Api::data('addgallery', array('gid' => 1234567, 'token' => 'abcdef0123'))['id'];

        $dates = array(
            self::$rotten => array('2025-11-25 11:25:00', '2026-01-03 10:00:00'),
            self::$pixiv => array('2026-11-03 19:38:00', '2026-01-02 10:00:00'),
            self::$manual => array('2024-01-01 12:00:00', '2026-01-04 10:00:00'),
            self::$another => array('2023-06-01 12:00:00', '2026-01-01 10:00:00'),
            self::$unarchived => array(null, '2026-01-05 10:00:00'),
        );
        foreach ($dates as $id => list($posted, $added)) {
            Db::exec('update gallery set posted = ?, added = ? where id = ?', array($posted, $added, $id));
        }

        Api::data('update', array('id' => self::$rotten, 'readStatus' => 1));
        Api::data('updateColor', array('id' => self::$pixiv, 'color' => 'red'));

        Stack::flushCache();
    }

    private static function ids(array $data): array
    {
        return array_map(fn ($g) => (int)$g['id'], $data['galleries']);
    }

    private static function list(array $params = array()): array
    {
        return Api::data('galleries', $params);
    }

    public function testListIsOrderedByPostedNewestFirst(): void
    {
        $data = self::list();

        $this->assertSame(array(self::$pixiv, self::$rotten, self::$manual, self::$another), self::ids($data));
        $this->assertSame('4', (string)$data['meta']['total']);
        $this->assertTrue($data['end']);
    }

    public function testListSnapshot(): void
    {
        $data = self::list();
        $data['meta'] = array('total' => $data['meta']['total']); // the frontend only uses meta.total

        Snapshot::assertMatches('api/galleries', Snapshot::normalize($data));
    }

    public function testPagination(): void
    {
        $first = self::list(array('page' => 0, 'pagesize' => 3));
        $second = self::list(array('page' => 1, 'pagesize' => 3));
        $third = self::list(array('page' => 2, 'pagesize' => 3));

        $this->assertSame(array(self::$pixiv, self::$rotten, self::$manual), self::ids($first));
        $this->assertFalse($first['end'], 'infinite scroll goes on');
        $this->assertSame('4', (string)$first['meta']['total'], 'total counts all matches, not the page');

        $this->assertSame(array(self::$another), self::ids($second));
        $this->assertTrue($second['end']);
        $this->assertSame('4', (string)$second['meta']['total']);

        $this->assertSame(array(), self::ids($third));
        $this->assertTrue($third['end']);
    }

    public function testOrderAdded(): void
    {
        $this->assertSame(
            array(self::$manual, self::$rotten, self::$pixiv, self::$another),
            self::ids(self::list(array('order' => 'added')))
        );
    }

    public function testOrderRandomIsStablePerSeed(): void
    {
        $a = self::ids(self::list(array('order' => 'random', 'seed' => 'abc')));
        $b = self::ids(self::list(array('order' => 'random', 'seed' => 'abc')));

        $this->assertSame($a, $b, 'same seed, same order (the frontend pages through a random list)');

        sort($a);
        $expected = array(self::$rotten, self::$pixiv, self::$manual, self::$another);
        sort($expected);
        $this->assertSame($expected, $a);
    }

    public function testOrderWeight(): void
    {
        $data = self::list(array('order' => 'weight', 'search' => 'stockings'));

        $this->assertEqualsCanonicalizing(array(self::$pixiv, self::$another), self::ids($data));
        foreach ($data['galleries'] as $gallery) {
            $this->assertArrayHasKey('ranked_weight', $gallery);
        }
    }

    public function testSearchByTagFindsExhentaiAndManualGalleries(): void
    {
        $this->assertSame(array(self::$rotten, self::$manual), self::ids(self::list(array('search' => 'glasses'))));
    }

    public function testSearchByNamespacedTag(): void
    {
        $this->assertSame(array(self::$pixiv, self::$another),
            self::ids(self::list(array('search' => 'female:stockings'))));
    }

    public function testSearchByName(): void
    {
        $this->assertSame(array(self::$pixiv), self::ids(self::list(array('search' => 'Momomimi'))));
        $this->assertSame(array(self::$another), self::ids(self::list(array('search' => 'another book'))));
    }

    public function testSearchPhrase(): void
    {
        $this->assertSame(array(self::$pixiv), self::ids(self::list(array('search' => '"chastity belt"'))));
    }

    public function testSearchExclusion(): void
    {
        $this->assertSame(array(self::$another), self::ids(self::list(array('search' => 'stockings -tomgirl'))));
    }

    public function testSearchWithoutHits(): void
    {
        $data = self::list(array('search' => 'nonexistingword'));

        $this->assertSame(array(), $data['galleries']);
        $this->assertSame('0', (string)$data['meta']['total']);
        $this->assertTrue($data['end']);
    }

    public function testSearchCombinedWithPaging(): void
    {
        $data = self::list(array('search' => 'stockings', 'pagesize' => 1));

        $this->assertSame(array(self::$pixiv), self::ids($data));
        $this->assertSame('2', (string)$data['meta']['total']);
        $this->assertFalse($data['end']);
    }

    public function testReadFilter(): void
    {
        $this->assertSame(array(self::$rotten), self::ids(self::list(array('read' => 1))));
        $this->assertSame(array(self::$pixiv, self::$manual, self::$another), self::ids(self::list(array('read' => 0))));
    }

    public function testColorFilter(): void
    {
        $this->assertSame(array(self::$pixiv), self::ids(self::list(array('color' => 'red'))));
        $this->assertSame(array(self::$rotten, self::$manual, self::$another),
            self::ids(self::list(array('color' => 'black'))));
    }

    public function testUnarchivedIncludesQueuedGalleries(): void
    {
        $ids = self::ids(self::list(array('unarchived' => 1)));

        $this->assertContains(self::$unarchived, $ids);
        $this->assertNotContains(self::$unarchived, self::ids(self::list()));
    }

    public function testListEntriesAreCached(): void
    {
        self::list();
        $data = self::list();

        foreach ($data['galleries'] as $gallery) {
            $this->assertTrue($gallery['fromcache']);
        }
    }

    public function testGallery(): void
    {
        $data = Api::data('gallery', array('id' => self::$pixiv));

        $this->assertSame('[Momomimi] Pixiv [English]', $data['name']);
        $this->assertSame('red', $data['color']);
        $this->assertSame(array('english', 'translated'), $data['tags']['language']);

        Snapshot::assertMatches('api/gallery', Snapshot::normalize($data));
    }

    public function testGalleryWithoutId(): void
    {
        $this->assertSame(array('ret' => false, 'message' => 'Invalid params'), Api::call('gallery'));
    }

    public function testSuggested(): void
    {
        $this->assertSame(array('stockings'), Api::data('suggested', array('term' => 'stock')));
        $this->assertContains('glasses', Api::data('suggested', array('term' => 'gla')));
        $this->assertSame(array(), Api::data('suggested', array('term' => '')));
    }

    public function testStats(): void
    {
        $stats = Api::data('stats');

        $this->assertSame('5', (string)$stats['count_total']);
        $this->assertSame('4', (string)$stats['count_archived']);
        $this->assertSame('0', (string)$stats['count_deleted']);
        $this->assertSame((string)Db::value('select sum(filesize) from gallery'), (string)$stats['filesize']);
    }

    public function testHasGallery(): void
    {
        $data = Api::data('hasgallery', array('gid' => 3654969));
        $this->assertSame(array('exists' => true, 'id' => (string)self::$rotten, 'archived' => true, 'deleted' => '0'), $data);

        $this->assertSame(array('exists' => false), Api::data('hasgallery', array('gid' => 1)));
        $this->assertSame('Invalid params', Api::call('hasgallery')['message']);
    }

    public function testHasGalleries(): void
    {
        $data = Api::data('hasgalleries', array('gids' => array(3654969, 4302753, 1234567, 1)));

        usort($data, fn ($a, $b) => $a['exhenid'] <=> $b['exhenid']);
        $this->assertSame(array(
            array('exhenid' => '1234567', 'archived' => '0', 'deleted' => '0'),
            array('exhenid' => '3654969', 'archived' => '1', 'deleted' => '0'),
            array('exhenid' => '4302753', 'archived' => '1', 'deleted' => '0'),
        ), $data);
    }

    public function testInvalidRequests(): void
    {
        $this->assertSame('Invalid action', Api::call('nosuchaction')['message']);

        $response = Api::get('/api.php');
        $this->assertSame('Invalid request', json_decode($response['body'], true)['message']);
        $this->assertSame('*', $response['headers']['access-control-allow-origin'] ?? null);
    }
}
