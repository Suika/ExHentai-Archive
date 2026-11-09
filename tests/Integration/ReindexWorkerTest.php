<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * The background reindex worker (docker/reindexer.sh) and the requests the app makes (classes/Search.php).
 */
final class ReindexWorkerTest extends TestCase
{
    private const TMP = APP_ROOT.'/tmp';

    protected function setUp(): void
    {
        Stack::reset();
    }

    private static function search(string $term): array
    {
        return array_map(fn ($g) => (int)$g['id'], Api::data('galleries', array('search' => $term))['galleries']);
    }

    public function testTasksMakeChangesSearchableWithAPartialRun(): void
    {
        $id = Stack::seedManualGallery('Partial Run Gallery', ZipFactory::standardEntries(), array('female:freckles'));

        $this->assertSame(array('delta', 'delta'), Stack::reindexHistory(), 'AddGallery and EditGallery: partial runs only');
        $this->assertSame(array($id), self::search('partial run'));
        $this->assertSame(array($id), self::search('freckles'), 'tags of the merged gallery are searchable');
        $this->assertContains('freckles', Api::data('suggested', array('term' => 'freck')), 'suggestions are updated too');
    }

    public function testPartialRunKeepsTheRestOfTheIndex(): void
    {
        $first = Stack::seedManualGallery('First Gallery', ZipFactory::standardEntries(), array('female:glasses'));
        $second = Stack::seedManualGallery('Second Gallery', ZipFactory::standardEntries(), array('female:glasses'));

        $this->assertEqualsCanonicalizing(array($first, $second), self::search('glasses'));
        $this->assertSame('2', (string)Api::data('galleries')['meta']['total']);
    }

    public function testAppTimeZoneDiffersFromTheDatabase(): void
    {
        // makes sure the suite runs with the setup that hid new galleries from the index (PHP ahead of MariaDB)
        $dbOffset = Db::value("select time_format(timediff(now(), utc_timestamp()), '%H:%i')");

        $this->assertNotSame(ltrim(date('P'), '+'), $dbOffset, 'TZ of the test container (docker-compose.test.yml)');
    }

    public function testRequestsAreCombined(): void
    {
        foreach (array('delta', 'delta', 'full', 'delta') as $kind) {
            touch(self::TMP."/reindex-$kind.request");
            usleep(20000);
        }
        Stack::waitForReindex();

        $this->assertSame(array('full'), Stack::reindexHistory(), 'one full run covers all requests');
    }

    public function testCleanupRemovesGalleriesWithAFullRun(): void
    {
        $id = Stack::seedManualGallery('Removed Gallery', ZipFactory::standardEntries());
        $this->assertSame(array($id), self::search('removed gallery'));
        Api::data('deletegallery', array('id' => $id, 'key' => 'test-key'));

        $result = Stack::task('Cleanup');
        $this->assertSame(0, $result['code'], $result['output']);

        $this->assertSame('full', array_slice(Stack::reindexHistory(), -1)[0]);
        $this->assertSame(array(), self::search('removed gallery'), 'gone from the index');
    }

    public function testIndexerActionOnlyRequests(): void
    {
        $response = Api::request('indexer');

        $this->assertSame("Reindex requested\n", $response['body']);
        Stack::waitForReindex();
        $this->assertSame(array('full'), Stack::reindexHistory());
    }

    public function testDeleteIsAppliedToTheIndexDirectly(): void
    {
        $id = Stack::seedManualGallery('Delete Me', ZipFactory::standardEntries());
        $runs = count(Stack::reindexHistory());

        Api::data('deletegallery', array('id' => $id, 'key' => 'test-key'));

        $this->assertSame('1', (string)Db::gallery($id)['deleted']);
        $this->assertSame($runs, count(Stack::reindexHistory()), 'no reindex');
        $row = Api::data('galleries', array('search' => 'delete me'))['galleries'][0] ?? null;
        $this->assertNotNull($row, 'deleted (1) galleries stay listed until Cleanup, as before');
    }
}
