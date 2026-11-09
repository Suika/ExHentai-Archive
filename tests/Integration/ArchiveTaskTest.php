<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Fixtures;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;
use Tests\Unit\ExPage\GalleryParserTest;

/**
 * The Archive task against the fake ExHentai server: metadata, tags and archive download of queued
 * galleries, and the feed crawl.
 */
final class ArchiveTaskTest extends TestCase
{
    private const GID = 4302753;
    private const TOKEN = '6b515751d9';

    protected function setUp(): void
    {
        Stack::reset();
    }

    private function queue(array $galleryOptions = array()): string
    {
        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html', $galleryOptions);
        $zip = Stack::fake()->archive(self::GID, ZipFactory::standardEntries());
        Api::data('addgallery', array('gid' => self::GID, 'token' => self::TOKEN));

        return $zip;
    }

    private function archive(): array
    {
        $result = Stack::task('Archive');
        $this->assertSame(0, $result['code'], $result['output']);

        return $result;
    }

    public function testDownloadsAQueuedGallery(): void
    {
        $zip = $this->queue();
        $this->archive();

        $row = Db::galleryByExhenid(self::GID);
        $this->assertSame(
            array('[Momomimi] Pixiv [English]', '[もも みみ]', 'Doujinshi', '2026-11-03 19:38:00', '1', '1', '5'),
            array($row['name'], $row['origtitle'], $row['type'], $row['posted'], (string)$row['hasmeta'],
                (string)$row['archived'], (string)$row['numfiles']),
            'numfiles counts the pages, not the "pages/" directory entry'
        );
        $this->assertSame((string)filesize($zip), (string)$row['filesize']);

        $stored = Stack::archivePath('galleries/'.self::GID.'.zip');
        $this->assertFileEquals($zip, $stored);
        $this->assertFileEquals(Fixtures::path('gallery-'.self::GID.'.html'), Stack::archivePath('pages/'.self::GID.'.html'),
            'the gallery page is kept (exgallerythumb and re-parsing use it)');

        $parsed = GalleryParserTest::extract(Fixtures::html('gallery-'.self::GID.'.html'));
        $this->assertSame($parsed['tags'], Api::data('gallery', array('id' => $row['id']))['tags']);
        $this->assertSame(count($parsed['properties']), Db::count('galleryproperty', 'gallery_id = ?', array($row['id'])));
    }

    public function testArchiverConversation(): void
    {
        $this->queue();
        $this->archive();

        $requests = array_map(fn ($r) => array($r['method'], $r['uri'], $r['post']), Stack::fake()->requests());
        $this->assertSame(array(
            array('GET', '/g/4302753/6b515751d9/?p=0', array()),
            array('GET', '/archiver.php?gid=4302753&token=6b515751d9', array()),
            array('POST', '/archiver.php?gid=4302753&token=6b515751d9', array('dlcheck' => 'true', 'dltype' => 'org')),
            array('GET', '/archive/4302753.zip?start=1', array()),
        ), $requests);

        $cookie = Stack::fake()->requests()[0]['cookie'];
        $this->assertStringContainsString('ipb_member_id=', $cookie, 'requests carry the account cookies');
        $this->assertStringContainsString('igneous=', $cookie);
    }

    public function testFoundGalleryIsSearchable(): void
    {
        $this->queue();
        $this->archive(); // runs the indexer itself

        $ids = array_map(fn ($g) => (int)$g['id'], Api::data('galleries', array('search' => 'tomgirl'))['galleries']);
        $this->assertSame(array((int)Db::galleryByExhenid(self::GID)['id']), $ids);
    }

    public function testMetadataOnlyWhenNotDownloading(): void
    {
        $this->queue();
        Db::exec('update gallery set download = 0 where exhenid = ?', array(self::GID));

        $this->archive();

        $row = Db::galleryByExhenid(self::GID);
        $this->assertSame(array('1', '0'), array((string)$row['hasmeta'], (string)$row['archived']));
        $this->assertSame('[Momomimi] Pixiv [English]', $row['name']);
        $this->assertCount(1, Stack::fake()->requests(), 'only the gallery page');
        $this->assertFileDoesNotExist(Stack::archivePath('galleries/'.self::GID.'.zip'));
    }

    public function testMissingArchiverLink(): void
    {
        $this->queue(array('replace' => array('archiver.php' => 'nothing.php')));

        $result = $this->archive();

        $this->assertStringContainsString('Failed to find archiver link', $result['output']);
        $this->assertSame('0', (string)Db::galleryByExhenid(self::GID)['archived']);
    }

    public function testInsufficientCredits(): void
    {
        $this->queue();
        Stack::fake()->archiver('credits');

        $result = $this->archive();

        $this->assertStringContainsString('Insufficient Credits', $result['output']);
        $this->assertSame('0', (string)Db::galleryByExhenid(self::GID)['archived']);
    }

    public function testDownloadIsNotAZip(): void
    {
        $this->queue();
        Stack::fake()->archiveRaw(self::GID, '<html>error page</html>');

        $result = $this->archive();

        $this->assertStringContainsString('Downloaded file is not an archive', $result['output']);
        $this->assertSame('0', (string)Db::galleryByExhenid(self::GID)['archived']);
    }

    public function testGalleryPageUnavailable(): void
    {
        Api::data('addgallery', array('gid' => 999, 'token' => 'abc'));

        $result = $this->archive();

        $this->assertStringContainsString('Failed to retrieve page from server', $result['output']);
        $this->assertSame(array('0', '0'), array_map('strval',
            array_values(array_intersect_key(Db::galleryByExhenid(999), array_flip(array('archived', 'hasmeta'))))));
    }

    public function testLargeArchiveSizeFits(): void
    {
        // B7: archives over 4 GiB (gallery.filesize bigint)
        $this->queue();
        $this->archive();
        Db::exec('update gallery set filesize = 5600000000 where exhenid = ?', array(self::GID));

        $this->assertSame('5600000000', (string)Api::data('stats')['filesize']);
    }

    private function feed(): array
    {
        Db::exec("insert into feed (term, archived, disabled, download) values ('eng', 0, 0, 0)");
        Stack::fake()->index(array('' => 'index-page.html', '4314775' => 'index-last.html'));

        return $this->archive();
    }

    private function indexRequests(): array
    {
        $uris = array_map(fn ($r) => $r['uri'], Stack::fake()->requests());
        return array_values(array_filter($uris, fn ($u) => strpos($u, '/?') === 0));
    }

    public function testFeedCrawl(): void
    {
        $this->feed();

        $this->assertSame(50, Db::count('gallery', 'feed_id = 1'), 'the galleries of both result pages were added');
        $this->assertSame('[Yaki Karubi] Jikan Teishi! Akuyou Chuui♡ / Time Stop! Don\'t Misuse It ♡ [English] {Doujins.com}',
            Db::galleryByExhenid(4315338)['name']);
        $this->assertSame('0', (string)Db::galleryByExhenid(4315338)['download'], 'feed without download: metadata only');
        $this->assertSame('1', (string)Db::value('select archived from feed'), 'the feed reached its last page');

        $this->assertSame(array(
            '/?f_cats=1017&f_search=eng',
            '/?next=4314775&f_cats=1017&f_search=eng',
        ), $this->indexRequests(), 'cursor paging, Doujinshi and Manga only');
    }

    public function testCrawledFeedStopsAtKnownGalleries(): void
    {
        $this->feed();
        Stack::fake()->clearRequests();

        $this->archive();

        $this->assertSame(array('/?f_cats=1017&f_search=eng'), $this->indexRequests(),
            'a completely crawled feed only checks its first page for new galleries');
        $this->assertSame(50, Db::count('gallery', 'feed_id = 1'));
    }

    public function testFeedDownloadsNewGalleries(): void
    {
        Db::exec("insert into feed (term, archived, disabled, download) values ('eng', 0, 0, 1)");
        $page = '<table class="itg gltc"><tr><td class="gl3c glname"><a href="https://exhentai.org/g/'.self::GID.'/'.self::TOKEN.'/">'
            .'<div class="glink">[Momomimi] Pixiv [English]</div></a></td></tr></table><div class="searchnav"><span id="dnext">Next</span></div>';
        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html');
        Stack::fake()->archive(self::GID, ZipFactory::standardEntries());
        Stack::fake()->indexHtml($page);

        $this->archive();

        $row = Db::galleryByExhenid(self::GID);
        $this->assertSame(array('1', '1', '1'), array((string)$row['feed_id'], (string)$row['download'], (string)$row['archived']));
    }
}
