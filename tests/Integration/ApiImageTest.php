<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * Reader pages (archiveimage), thumbnails/covers (gallerythumb, exgallerythumb) and archive downloads.
 */
final class ApiImageTest extends TestCase
{
    private static int $gallery;
    private static array $entries;
    private static int $zip64;

    public static function setUpBeforeClass(): void
    {
        Stack::reset();

        self::$entries = ZipFactory::standardEntries(7);
        self::$gallery = Stack::seedManualGallery('Image Test Gallery', self::$entries);

        // ZIP64 archive (the format of every zip over 4 GB, which Phar could not read)
        $zip64 = APP_ROOT.'/tmp/zip64.zip';
        copy(APP_ROOT.'/tests/fixtures/archives/zip64.zip', $zip64);
        $result = Stack::task('AddGallery', $zip64, 'Zip64 Gallery');
        self::assertSame(0, $result['code'], $result['output']);
        self::$zip64 = (int)Db::value('select id from gallery where name = ?', array('Zip64 Gallery'));
        unlink($zip64);
    }

    private static function page(int $id, int $index, array $params = array()): array
    {
        return Api::request('archiveimage', array('id' => $id, 'index' => $index) + $params);
    }

    public function testPagesInNaturalOrderWithOriginalBytes(): void
    {
        foreach (ZipFactory::pageOrder() as $index => $name) {
            $response = self::page(self::$gallery, $index);

            $this->assertSame(200, $response['status'], $name);
            $this->assertSame(self::$entries[$name], $response['body'], "page $index is $name, sent unchanged");
            $this->assertSame('max-age=31536000', $response['headers']['cache-control']);
            $this->assertArrayHasKey('last-modified', $response['headers']);
        }
    }

    public function testDirectoryEntriesAreNoPages(): void
    {
        $this->assertSame(404, self::page(self::$gallery, count(ZipFactory::pageOrder()))['status']);
        $this->assertSame(404, self::page(self::$gallery, 99)['status']);
    }

    public function testJpegContentType(): void
    {
        $this->assertSame('image/jpeg', self::page(self::$gallery, 0)['headers']['content-type']);
    }

    public function testContentTypeMatchesThePageFormat(): void
    {
        // from the file extension, case-insensitive
        $this->assertSame('image/png', self::page(self::$gallery, 1)['headers']['content-type'], '2.PNG');
        $this->assertSame('image/webp', self::page(self::$gallery, 2)['headers']['content-type'], '3.webp');
        $this->assertSame('image/gif', self::page(self::$gallery, 3)['headers']['content-type'], '4.gif');
    }

    public function testResizedPagesKeepTheirFormat(): void
    {
        $expected = array('1.jpg' => IMAGETYPE_JPEG, '2.PNG' => IMAGETYPE_PNG, '3.webp' => IMAGETYPE_WEBP,
            '4.gif' => IMAGETYPE_GIF, '10.jpg' => IMAGETYPE_JPEG);

        foreach (ZipFactory::pageOrder() as $index => $name) {
            $response = self::page(self::$gallery, $index, array('resize' => 100));
            $info = getimagesizefromstring($response['body']);

            $this->assertSame($expected[$name], $info[2], $name);
            $this->assertSame($info['mime'], $response['headers']['content-type'], $name);
        }
    }

    public function testResize(): void
    {
        $response = self::page(self::$gallery, 0, array('resize' => 200)); // 1.jpg, 800x1200

        $this->assertSame(200, $response['status']);
        $info = getimagesizefromstring($response['body']);
        $this->assertSame(array(200, 300, IMAGETYPE_JPEG), array($info[0], $info[1], $info[2]));
    }

    public function testResizeNeverUpscales(): void
    {
        $info = getimagesizefromstring(self::page(self::$gallery, 0, array('resize' => 5000))['body']);

        $this->assertSame(array(800, 1200), array($info[0], $info[1]));
    }

    public function testResizeOfEveryFormat(): void
    {
        foreach (ZipFactory::pageOrder() as $index => $name) {
            $response = self::page(self::$gallery, $index, array('resize' => 100));
            $info = getimagesizefromstring($response['body']);

            $this->assertNotFalse($info, "$name resized is an image");
            $this->assertSame(100, $info[0], $name);
        }
    }

    public function testInvalidRequests(): void
    {
        $this->assertSame(400, Api::request('archiveimage', array('index' => 0))['status']);
        $this->assertSame(404, self::page(999999, 0)['status']);
    }

    public function testZip64Archive(): void
    {
        $zip = new \ZipArchive();
        $zip->open(APP_ROOT.'/tests/fixtures/archives/zip64.zip');

        foreach (array('1.jpg', '2.png', '3.jpg') as $index => $name) {
            $response = self::page(self::$zip64, $index);
            $this->assertSame(200, $response['status'], $name);
            $this->assertSame($zip->getFromName($name), $response['body'], $name);
        }

        $info = getimagesizefromstring(self::page(self::$zip64, 0, array('resize' => 350))['body']);
        $this->assertSame(350, $info[0]);
    }

    public function testCover(): void
    {
        $first = Api::request('gallerythumb', array('id' => self::$gallery, 'index' => 0, 'type' => 1));

        $this->assertSame(302, $first['status']);
        $this->assertMatchesRegularExpression('~^/images/\d{6}\.jpeg$~', $first['headers']['location']);

        $image = Api::get($first['headers']['location']);
        $this->assertSame(200, $image['status'], 'nginx serves the cover from the images volume');
        $info = getimagesizefromstring($image['body']);
        $this->assertSame(array(350, 525, IMAGETYPE_JPEG), array($info[0], $info[1], $info[2]));

        $second = Api::request('gallerythumb', array('id' => self::$gallery, 'index' => 0, 'type' => 1));
        $this->assertSame($first['headers']['location'], $second['headers']['location'], 'created once, then reused');

        $listed = Api::data('galleries')['galleries'];
        $entry = array_values(array_filter($listed, fn ($g) => (int)$g['id'] === self::$gallery))[0];
        $this->assertSame(array('url' => $first['headers']['location'], 'landscape' => false), $entry['thumb'],
            'the list shows the cover (cache was cleared)');
    }

    public function testSmallThumbnail(): void
    {
        $response = Api::request('gallerythumb', array('id' => self::$gallery, 'index' => 1, 'type' => 2));
        $this->assertSame(302, $response['status']);

        $info = getimagesizefromstring(Api::get($response['headers']['location'])['body']); // 2.PNG 600x800
        $this->assertSame(array(140, 187), array($info[0], $info[1]));
    }

    public function testThumbnailInvalidRequests(): void
    {
        $this->assertSame(400, Api::request('gallerythumb', array('id' => self::$gallery, 'index' => 0, 'type' => 3))['status']);
        $this->assertSame(404, Api::request('gallerythumb', array('id' => self::$gallery, 'index' => 50, 'type' => 2))['status']);
    }

    /**
     * A not yet archived gallery whose stored ExHentai page has the given cover style (exgallerythumb).
     */
    private static function exGallery(string $coverStyle): int
    {
        static $exhenid = 900000;
        $exhenid++;
        Db::exec("insert into gallery (exhenid, hash, name, posted, archived) values (?, 'abc', 'Ex Cover', '2020-01-02 03:04:00', 0)",
            array($exhenid));
        $pages = Stack::archivePath('pages');
        if (!is_dir($pages)) {
            mkdir($pages, 0777, true);
        }
        file_put_contents("$pages/$exhenid.html", '<html><body><div id="gd1"><div style="'.$coverStyle.'"></div></div></body></html>');

        return (int)Db::value('select id from gallery where exhenid = ?', array($exhenid));
    }

    private static function exCover(string $name): string
    {
        $base = getenv('FAKE_EXHENTAI_URL') ?: 'http://127.0.0.1:8090';

        return "width:250px; height:354px; background:transparent url($base/thumb/$name.jpg) 0 0 no-repeat";
    }

    public function testExGalleryThumb(): void
    {
        $response = Api::request('exgallerythumb', array('id' => self::exGallery(self::exCover('cover'))));

        $this->assertSame(200, $response['status']);
        $this->assertSame('image/jpeg', $response['headers']['content-type']);
        $this->assertSame('public', $response['headers']['cache-control']);
        $this->assertSame(array(20, 30), array_slice(getimagesizefromstring($response['body']), 0, 2));
    }

    public function testExGalleryThumbFailureIsNotCached(): void
    {
        $response = Api::request('exgallerythumb', array('id' => self::exGallery(self::exCover('missing'))));

        $this->assertSame(502, $response['status']);
        $this->assertArrayNotHasKey('cache-control', $response['headers']);
        $this->assertArrayNotHasKey('expires', $response['headers']);
    }

    public function testExGalleryThumbTimesOut(): void
    {
        $id = self::exGallery(self::exCover('hang'));

        $start = microtime(true);
        $response = Api::request('exgallerythumb', array('id' => $id));
        $seconds = microtime(true) - $start;

        $this->assertSame(502, $response['status']);
        $this->assertLessThan(13, $seconds, 'gives up after 10 s');
        $this->assertArrayNotHasKey('cache-control', $response['headers']);
    }

    public function testExGalleryThumbWithoutCoverInPage(): void
    {
        $this->assertSame(404, Api::request('exgallerythumb', array('id' => self::exGallery('color:red')))['status']);
    }

    public function testDownload(): void
    {
        $file = APP_ROOT.'/archive/galleries/1-'.self::$gallery.'.zip';
        $response = Api::request('download', array('id' => self::$gallery));

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/zip', $response['headers']['content-type']);
        $this->assertSame('attachment; filename="Image Test Gallery.zip"; filename*=UTF-8\'\'Image%20Test%20Gallery.zip',
            $response['headers']['content-disposition']);
        $this->assertSame((string)filesize($file), $response['headers']['content-length']);
        $this->assertSame(file_get_contents($file), $response['body']);
    }

    public function testDownloadNameWithQuotesAndJapanese(): void
    {
        $id = Stack::seedManualGallery('[もも みみ] "Quoted" a/b', ZipFactory::standardEntries());

        $disposition = Api::request('download', array('id' => $id))['headers']['content-disposition'];

        $this->assertSame('attachment; filename="[__ __] _Quoted_ a_b.zip"; '
            ."filename*=UTF-8''".rawurlencode('[もも みみ] _Quoted_ a_b.zip'), $disposition);
    }

    public function testDownloadInvalidRequests(): void
    {
        $this->assertSame(400, Api::request('download')['status']);
        $this->assertSame(404, Api::request('download', array('id' => 999999))['status']);
    }
}
