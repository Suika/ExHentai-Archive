<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * The search index (SQLite FTS5, classes/Search.php) follows every change right away: no reindexing.
 */
final class SearchIndexTest extends TestCase
{
    protected function setUp(): void
    {
        Stack::reset();
    }

    private static function search(string $term): array
    {
        return array_map(fn ($g) => (int)$g['id'], Api::data('galleries', array('search' => $term))['galleries']);
    }

    public function testNewAndEditedGalleriesAreSearchableRightAway(): void
    {
        $id = Stack::seedManualGallery('Fresh Gallery', ZipFactory::standardEntries(), array('female:freckles'));

        $this->assertSame(array($id), self::search('fresh gallery'));
        $this->assertSame(array($id), self::search('freckles'), 'tag added by EditGallery');
        $this->assertSame(array($id), self::search('female:freckles'));
        $this->assertSame(array($id), self::search('female'), 'namespace alone, as with the former index');
        $this->assertContains('freckles', Api::data('suggested', array('term' => 'freck')));
    }

    public function testJapaneseTitlePartsAreFound(): void
    {
        $id = Stack::seedManualGallery('東方プロジェクト合同誌', ZipFactory::standardEntries());

        $this->assertSame(array($id), self::search('東方'));
        $this->assertSame(array($id), self::search('プロジェクト'));
        $this->assertSame(array(), self::search('西方'));
    }

    public function testInvalidSearchSyntaxIsNoError(): void
    {
        Stack::seedManualGallery('Some Gallery', ZipFactory::standardEntries());

        foreach (array('"', 'a AND', 'NEAR(', '*', '-', '|', 'col:"x', '^$', ')(') as $input) {
            $response = Api::call('galleries', array('search' => $input));
            $this->assertTrue($response['ret'], $input);
        }
        $this->assertCount(0, self::search('-some'), 'only an exclusion');
        $this->assertCount(1, self::search('-other'));
    }

    public function testCleanupRemovesGalleriesFromTheIndex(): void
    {
        $id = Stack::seedManualGallery('Removed Gallery', ZipFactory::standardEntries());
        Api::data('deletegallery', array('id' => $id, 'key' => 'test-key'));
        $this->assertSame(array($id), self::search('removed gallery'), 'deleted (1) galleries stay listed until Cleanup, as before');

        $result = Stack::task('Cleanup');
        $this->assertSame(0, $result['code'], $result['output']);

        $this->assertSame(array(), self::search('removed gallery'));
        $this->assertSame(0, Db::count('gallery_fts'));
    }

    public function testIndexerActionRebuilds(): void
    {
        $id = Stack::seedManualGallery('Rebuilt Gallery', ZipFactory::standardEntries());
        Db::exec('delete from gallery_fts');
        $this->assertSame(array(), self::search('rebuilt'));

        $response = Api::request('indexer');

        $this->assertSame("Search index rebuilt: 1 galleries\n", $response['body']);
        $this->assertSame(array($id), self::search('rebuilt'));
    }
}
