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
        // shape of the Thumbnail list view: a cover link and a title link per gallery
        $html = '<div class="itg gld">'
            .'<div class="gl1t"><a href="https://exhentai.org/g/11/aaaa/"><div class="gl3t"><img src="x.webp"></div></a>'
            .'<a href="https://exhentai.org/g/11/aaaa/"><div class="glink">First Title</div></a></div>'
            .'<div class="gl1t"><a href="https://exhentai.org/g/12/bbbb/"><div class="glink">Second Title</div></a></div>'
            .'</div><div class="searchnav"><a id="dnext" href="https://exhentai.org/?f_search=x&amp;next=12">Next</a></div>';

        $data = self::extract($html);

        $this->assertSame(array(
            array('name' => 'First Title', 'exhenid' => '11', 'hash' => 'aaaa'),
            array('name' => 'Second Title', 'exhenid' => '12', 'hash' => 'bbbb'),
        ), $data['galleries']);
        $this->assertSame('12', $data['nextCursor']);
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
