<?php

namespace Tests\Unit\ExPage;

use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Snapshot;

/**
 * Search result page parsing (ExPage_Index), used by the feed crawl of the Archive task.
 * The fixtures are current ExHentai search pages in the Compact list view with cursor paging.
 */
final class IndexParserTest extends TestCase
{
    private static function extract(string $html): array
    {
        $page = new \ExPage_Index($html);

        return array(
            'isLastPage' => $page->isLastPage(),
            'nextCursor' => $page->getNextCursor(),
            'galleries' => array_map(fn ($g) => (array)$g, $page->getGalleries()),
        );
    }

    public function testPageWithNextPage(): void
    {
        $data = self::extract(Fixtures::html('index-page.html'));

        $this->assertFalse($data['isLastPage']);
        $this->assertSame('4314775', $data['nextCursor']);
        $this->assertCount(25, $data['galleries']);
        $this->assertSame(array(
            'name' => "[Yaki Karubi] Jikan Teishi! Akuyou Chuui♡ / Time Stop! Don't Misuse It ♡ [English] {Doujins.com}",
            'exhenid' => '4315338',
            'hash' => '5952c385d2',
        ), $data['galleries'][0], 'the title without the tags shown in the same link');
        foreach ($data['galleries'] as $gallery) {
            $this->assertMatchesRegularExpression('/^\d+$/', $gallery['exhenid']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $gallery['hash']);
            $this->assertNotSame('', trim($gallery['name']));
        }

        Snapshot::assertMatches('parser/index-page', $data);
    }

    public function testLastPage(): void
    {
        $data = self::extract(Fixtures::html('index-last.html'));

        $this->assertTrue($data['isLastPage']);
        $this->assertNull($data['nextCursor']);
        $this->assertCount(25, $data['galleries']);

        Snapshot::assertMatches('parser/index-last', $data);
    }

    public function testThumbnailView(): void
    {
        // a real search page in the Thumbnail list view: a title link and a cover link per gallery
        $data = self::extract(Fixtures::html('index-thumbnail.html'));

        $this->assertFalse($data['isLastPage']);
        $this->assertSame('4307766', $data['nextCursor']);
        $this->assertCount(100, $data['galleries']);
        $this->assertSame(array(
            'name' => '[sakidesu-zrkc] [導入/セリフ差分５枚]搾精魔女コス里沙と…',
            'exhenid' => '4316052',
            'hash' => 'dd1f8b2c58',
        ), $data['galleries'][0]);
        foreach ($data['galleries'] as $gallery) {
            $this->assertNotSame('', trim($gallery['name']));
        }

        Snapshot::assertMatches('parser/index-thumbnail', $data);
    }

    public function testLinksOutsideTheListAreIgnored(): void
    {
        // e.g. a gallery page has /g/ links (versions, parent) but no result list
        $data = self::extract(Fixtures::html('gallery-newer.html'));

        $this->assertSame(array(), $data['galleries']);
        $this->assertTrue($data['isLastPage']);
    }

    public function testNoHits(): void
    {
        $data = self::extract('<html><body><p>No hits found</p></body></html>');

        $this->assertSame(array(), $data['galleries']);
        $this->assertTrue($data['isLastPage'], 'an empty or error page ends the crawl');
    }
}
