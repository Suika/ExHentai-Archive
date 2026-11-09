<?php

namespace Tests\Unit\ExPage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Snapshot;

/**
 * Gallery page parsing (ExPage_Gallery) against saved ExHentai pages. The snapshots in
 * tests/snapshots/parser/ hold everything the app takes from a gallery page.
 */
final class GalleryParserTest extends TestCase
{
    public static function extract(string $html): array
    {
        $page = new \ExPage_Gallery($html);
        $newest = $page->getNewestVersion();

        return array(
            'isValid' => $page->isValid(),
            'name' => $page->getName(),
            'originalName' => $page->getOriginalName(),
            'type' => $page->getType(),
            'thumbnailUrl' => $page->getThumbnailUrl(),
            'tags' => $page->getTags(),
            'properties' => $page->getProperties(),
            'archiverUrl' => $page->getArchiverUrl(),
            'newestVersion' => $newest ? (array)$newest : $newest,
            'copyrightClaim' => $page->getCopyrightClaim(),
        );
    }

    public static function galleryPages(): array
    {
        $cases = array();
        foreach (Fixtures::galleryPages() as $file) {
            $cases[$file] = array($file);
        }

        return $cases;
    }

    #[DataProvider('galleryPages')]
    public function testSnapshot(string $file): void
    {
        Snapshot::assertMatches('parser/'.basename($file, '.html'), self::extract(Fixtures::html($file)));
    }

    public function testGalleryPage(): void
    {
        $data = self::extract(Fixtures::html('gallery-4302753.html'));

        $this->assertTrue($data['isValid']);
        $this->assertSame('[Momomimi] Pixiv [English]', $data['name']);
        $this->assertSame('[もも みみ]', $data['originalName']);
        $this->assertSame('Doujinshi', $data['type']);
        $this->assertSame(array('english', 'translated'), $data['tags']['language']);
        $this->assertSame(array('arknights', 'cyberpunk', 'original'), $data['tags']['parody']);
        $this->assertSame('2026-11-03 19:38', $data['properties']['Posted']);
        $this->assertSame('https://exhentai.org/archiver.php?gid=4302753&token=6b515751d9', $data['archiverUrl']);
        $this->assertSame('https://s.exhentai.org/w/02/770/60331-agpv6eoc.webp', $data['thumbnailUrl']);
        $this->assertFalse($data['newestVersion']);
    }

    public function testGalleryWithoutOriginalTitle(): void
    {
        $data = self::extract(Fixtures::html('gallery-3654969.html'));

        $this->assertSame('', $data['originalName']);
        $this->assertSame('Non-H', $data['type']);
        $this->assertSame(array('senukin | hornee borgar'), $data['tags']['artist'], 'tags keep their "|" alias text');
    }

    public function testNewerVersion(): void
    {
        $data = self::extract(Fixtures::html('gallery-newer.html'));

        $this->assertTrue($data['isValid']);
        $this->assertIsArray($data['newestVersion'], 'a "newer version" link is found');
        $this->assertMatchesRegularExpression('/^\d+$/', $data['newestVersion']['exhenid']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $data['newestVersion']['hash']);
    }

    public function testRemovedGalleryPage(): void
    {
        $html = Fixtures::has('gallery-removed.html') ? Fixtures::html('gallery-removed.html')
            : 'Key missing, or incorrect key provided.';

        $page = new \ExPage_Gallery($html);
        $this->assertFalse($page->isValid());
        $this->assertFalse($page->getNewestVersion());
        $this->assertFalse($page->getArchiverUrl());
    }

    public function testCopyrightClaim(): void
    {
        $this->assertSame('Irodori Comics', (new \ExPage_Gallery(Fixtures::html('gallery-copyright.html')))->getCopyrightClaim());
        $this->assertSame('Some Publisher Inc', (new \ExPage_Gallery(
            '<div class="d"><p>This gallery is unavailable due to a copyright claim by Some Publisher Inc.<br />Sorry about that.</p></div>'
        ))->getCopyrightClaim());
        $this->assertNull((new \ExPage_Gallery(Fixtures::html('gallery-4302753.html')))->getCopyrightClaim());
        $this->assertNull((new \ExPage_Gallery('Key missing, or incorrect key provided.'))->getCopyrightClaim());
    }

    public function testEmptyResponse(): void
    {
        $page = new \ExPage_Gallery('');
        $this->assertFalse($page->isValid());
        $this->assertSame('', $page->getName());
        $this->assertSame(array(), $page->getTags());
        $this->assertNull($page->getThumbnailUrl());
    }
}
