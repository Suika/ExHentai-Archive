<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * The ArchiveDebug task: shows and saves what ExHentai answers for one gallery, without changing the database.
 */
final class ArchiveDebugTaskTest extends TestCase
{
    private const GID = 4302753;
    private const TOKEN = '6b515751d9';

    protected function setUp(): void
    {
        Stack::reset();
    }

    private function run_(string ...$args): string
    {
        $result = Stack::task('ArchiveDebug', ...$args);
        $this->assertSame(0, $result['code'], $result['output']);

        return $result['output'];
    }

    /** the folder of the last run */
    private function dumpDir(): string
    {
        $dirs = glob(APP_ROOT.'/tmp/archive-debug/'.self::GID.'-*');
        $this->assertNotEmpty($dirs, 'dump folder');

        return end($dirs);
    }

    public function testValidGalleryStopsBeforeTheDownloadButton(): void
    {
        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html');
        Api::data('addgallery', array('gid' => self::GID, 'token' => self::TOKEN));
        $before = Db::galleryByExhenid(self::GID);

        $output = $this->run_((string)self::GID);

        $this->assertStringContainsString('Gallery page: HTTP 200', $output);
        $this->assertStringContainsString('Archiver URL: https://exhentai.org/archiver.php?gid='.self::GID, $output);
        $this->assertStringContainsString('dlcheck button: yes', $output);
        $this->assertStringContainsString('Stopped before the download button', $output);
        $this->assertStringNotContainsString('igneous=', $output, 'no cookie values');

        $dir = $this->dumpDir();
        foreach (array('01-gallery.html', '01-gallery.headers.txt', '02-archiver-form.html', 'summary.txt') as $file) {
            $this->assertFileExists($dir.'/'.$file);
        }
        $this->assertStringContainsString('HTTP/1.1 200', file_get_contents($dir.'/01-gallery.headers.txt'));

        $this->assertNotContains('POST', array_column(Stack::fake()->requests(), 'method'));
        $this->assertSame($before, Db::galleryByExhenid(self::GID), 'the database is not changed');
    }

    public function testUnknownGalleryShowsTheAnswer(): void
    {
        // not in the database: token given; the fake server answers unknown galleries like ExHentai
        $output = $this->run_((string)self::GID, 'abcdef0123');

        $this->assertStringContainsString('Gallery page: HTTP 404', $output);
        $this->assertStringContainsString('Text: "Key missing, or incorrect key provided."', $output);
        $this->assertStringContainsString('wrong token (hash) for this gallery', $output);
        $this->assertStringContainsString('the Archive task stops here', $output);
        $this->assertStringNotContainsString('Archiver URL', $output);
    }

    public function testContentWarningPage(): void
    {
        $warning = '<html><head><title>ExHentai.org</title></head><body><div class="d"><h1>Content Warning</h1>'
            .'<p>This gallery has been flagged as Offensive For Everyone.</p>'
            .'<p><a href="https://exhentai.org/g/'.self::GID.'/'.self::TOKEN.'/?nw=session">View Gallery</a></p></div></body></html>';
        Stack::fake()->galleryHtml(self::GID, $warning);
        Api::data('addgallery', array('gid' => self::GID, 'token' => self::TOKEN));

        $output = $this->run_((string)self::GID);
        $this->assertStringContainsString('Found "Content Warning"', $output);

        // the Archive task's log line now tells the same
        $archive = Stack::task('Archive')['output'];
        $this->assertStringContainsString('ExHentai returned no gallery page (removed or invalid): HTTP 200, "ExHentai.org": '
            .'"Content Warning This gallery has been flagged as Offensive For Everyone. View Gallery"', $archive);
    }

    public function testDownload(): void
    {
        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html');
        Stack::fake()->archive(self::GID, ZipFactory::standardEntries());
        Api::data('addgallery', array('gid' => self::GID, 'token' => self::TOKEN));

        $output = $this->run_((string)self::GID, 'download');

        $this->assertStringContainsString('Continue URL:', $output);
        $this->assertStringContainsString('Download: OK', $output);
        $this->assertStringContainsString('Zip: OK, 5 pages', $output);
        $this->assertFileExists($this->dumpDir().'/03-archiver-result.html');
        $this->assertFileDoesNotExist(Stack::archivePath('debug/'.self::GID.'.zip'), 'deleted without "keep"');
        $this->assertSame('0', (string)Db::galleryByExhenid(self::GID)['archived']);
    }
}
