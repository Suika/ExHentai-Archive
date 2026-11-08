<?php

namespace Tests\Unit\ExPage;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\Fixtures;
use Tests\Support\Snapshot;

/**
 * Feed (front page search) parsing: ExPage_Index, used by the Archive task.
 *
 * Known bug: the fixtures are current ExHentai search pages (Compact list view "itg gltc", cursor
 * pagination "?next=<gid>" with #dnext). ExPage_Index only knows the old Thumbnail grid (.itg.gld .gl1t)
 * and the old numbered pager (td.ptds + td.ptdd), so it finds no galleries and never sees the last page.
 */
final class IndexParserTest extends TestCase
{
    private static function extract(string $html): array
    {
        $page = new \ExPage_Index($html);

        return array(
            'isLastPage' => $page->isLastPage(),
            'galleries' => array_map(fn ($g) => (array)$g, $page->getGalleries()),
        );
    }

    #[Group('known-bug')]
    public function testPageWithNextPage(): void
    {
        $data = self::extract(Fixtures::html('index-page.html'));

        $this->assertFalse($data['isLastPage']);
        $this->assertNotEmpty($data['galleries']);
        foreach ($data['galleries'] as $gallery) {
            $this->assertMatchesRegularExpression('/^\d+$/', $gallery['exhenid']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $gallery['hash']);
            $this->assertNotSame('', trim($gallery['name']));
        }

        Snapshot::assertMatches('parser/index-page', $data);
    }

    #[Group('known-bug')]
    public function testLastPage(): void
    {
        $data = self::extract(Fixtures::html('index-last.html'));

        $this->assertTrue($data['isLastPage']);
        $this->assertNotEmpty($data['galleries']);

        Snapshot::assertMatches('parser/index-last', $data);
    }

    public function testNoHits(): void
    {
        $data = self::extract('<html><body><p>No hits found</p></body></html>');

        $this->assertSame(array(), $data['galleries']);
    }
}
