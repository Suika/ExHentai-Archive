<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Fixtures;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * Audit / ForceAudit against the fake ExHentai server: tag updates, newer versions, removed galleries.
 */
final class AuditTaskTest extends TestCase
{
    private const GID = 4302753;
    private const TOKEN = '6b515751d9';

    private int $id;

    protected function setUp(): void
    {
        Stack::reset();
        $this->id = $this->archived(self::GID, self::TOKEN, 'gallery-'.self::GID.'.html');
    }

    /** Downloads a gallery through the Archive task and makes it old enough for Audit */
    private function archived(int $gid, string $token, string $fixture): int
    {
        Stack::fake()->gallery($gid, $fixture);
        Stack::fake()->archive($gid, ZipFactory::standardEntries());
        Api::data('addgallery', array('gid' => $gid, 'token' => $token));
        $result = Stack::task('Archive');
        $this->assertSame(0, $result['code'], $result['output']);

        $id = (int)Db::galleryByExhenid($gid)['id'];
        Db::exec('update gallery set added = now() - interval 10 day, posted = now() - interval 30 day where id = ?', array($id));

        return $id;
    }

    private function runTask(string $task, string ...$args): string
    {
        $result = Stack::task($task, ...$args);
        $this->assertSame(0, $result['code'], $result['output']);

        return $result['output'];
    }

    private function tags(): array
    {
        return Api::data('gallery', array('id' => $this->id))['tags'];
    }

    public function testUnchangedGallery(): void
    {
        $tags = $this->tags();

        $this->runTask('Audit');

        $row = Db::gallery($this->id);
        $this->assertNotNull($row['lastaudit']);
        $this->assertSame('0', (string)$row['deleted']);
        $this->assertSame($tags, $this->tags());
    }

    public function testChangedTagsAreReplaced(): void
    {
        Api::data('galleries'); // cache the list entry
        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html', array('replace' => array('tomgirl' => 'twintails')));

        $output = $this->runTask('Audit');

        $this->assertStringContainsString('Different tags found', $output);
        $male = $this->tags()['male'];
        $this->assertContains('twintails', $male);
        $this->assertNotContains('tomgirl', $male);

        $listed = Api::data('galleries')['galleries'][0];
        $this->assertContains('twintails', $listed['tags']['male'], 'cached list entry was dropped');
    }

    public function testNewerVersionReplacesTheGallery(): void
    {
        Fixtures::requireAll('gallery-newer-older.html');
        $old = $this->archived(4161523, 'ebe6633549', 'gallery-newer-older.html');
        // the newest version linked from that page; any valid gallery page will do
        Stack::fake()->gallery(4313473, 'gallery-'.self::GID.'.html');

        $output = $this->runTask('ForceAudit', 'id', (string)$old);

        $this->assertStringContainsString('New gallery found', $output);
        $row = Db::gallery($old);
        $this->assertSame('1', (string)$row['deleted'], 'the old version is removed by the next Cleanup');
        $this->assertNull($row['lastaudit']);

        $new = Db::galleryByExhenid(4313473);
        $this->assertNotNull($new, 'the newest version is queued');
        $this->assertSame(array('ac542aef1f', '1', '0'),
            array($new['hash'], (string)$new['download'], (string)$new['archived']));
    }

    public function testRemovedGalleryIsMarkedAudited(): void
    {
        $tags = $this->tags();
        Fixtures::requireAll('gallery-removed.html');
        Stack::fake()->gallery(self::GID, 'gallery-removed.html', array('status' => 404)); // as ExHentai sends it

        $output = $this->runTask('Audit');

        $this->assertStringContainsString('Gallery is unavailable: copyright claim by Irodori Comics', $output);
        $row = Db::gallery($this->id);
        $this->assertNotNull($row['lastaudit']);
        $this->assertSame('0', (string)$row['deleted']);
        $this->assertSame($tags, $this->tags(), 'tags are kept');
        $this->assertSame(array('1', '1'), array((string)$row['archived'], (string)$row['download']),
            'an archived gallery stays archived and listed by hasgallery');
    }

    public function testInvalidPageIsMarkedAudited(): void
    {
        Stack::fake()->galleryHtml(self::GID, '<html><body>Something went wrong</body></html>');

        $output = $this->runTask('Audit');

        $this->assertStringContainsString('Gallery was either removed or was invalid', $output);
        $this->assertNotNull(Db::gallery($this->id)['lastaudit']);
    }

    public function testServerErrorLeavesTheGalleryUnaudited(): void
    {
        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html', array('status' => 503));

        $output = $this->runTask('Audit');

        $this->assertStringContainsString('Request failed (HTTP 503), the gallery is checked again by the next run', $output);
        $this->assertNull(Db::gallery($this->id)['lastaudit']);

        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html');
        $this->runTask('Audit');
        $this->assertNotNull(Db::gallery($this->id)['lastaudit'], 'checked by the next run');
    }

    public function testHangingRequestLeavesTheGalleryUnaudited(): void
    {
        Stack::fake()->gallery(self::GID, 'gallery-'.self::GID.'.html', array('sleep' => 4)); // requestTimeout is 2 s

        $output = $this->runTask('Audit');

        $this->assertStringContainsString('Request failed (Operation timed out', $output);
        $this->assertNull(Db::gallery($this->id)['lastaudit']);
    }

    public function testOutageStopsTheRun(): void
    {
        // more galleries due for an audit than the failure limit
        for ($i = 1; $i <= 7; $i++) {
            Db::exec("insert into gallery (exhenid, hash, name, archived, download, deleted, source, added, posted, updated)
                values (?, 'abc', ?, 1, 1, 0, 0, now() - interval 10 day, now() - interval 30 day, now())", array(1000 + $i, "Outage $i"));
        }
        Stack::fake()->block('down', 1000);
        Stack::fake()->clearRequests();

        $start = microtime(true);
        $output = $this->runTask('Audit');

        $this->assertStringContainsString('ExHentai not reachable (5 failed requests in a row), stopping this run', $output);
        $this->assertCount(5, Stack::fake()->requests());
        $this->assertSame(0, Db::count('gallery', 'lastaudit is not null'), 'nothing marked as audited');
        $this->assertLessThan(30, microtime(true) - $start);
    }

    public function testBanStopsTheAudit(): void
    {
        Stack::fake()->block('ban', 100);

        $output = $this->runTask('Audit');

        $this->assertStringContainsString('IP temporarily banned by ExHentai, expires in 2 hours and 13 minutes: stopped this run', $output);
        $this->assertNull(Db::gallery($this->id)['lastaudit']);
    }

    public function testFailedNewerVersionRequestChangesNothing(): void
    {
        Fixtures::requireAll('gallery-newer-older.html');
        $old = $this->archived(4161523, 'ebe6633549', 'gallery-newer-older.html');
        Stack::fake()->gallery(4313473, 'gallery-'.self::GID.'.html', array('status' => 503));

        $this->runTask('ForceAudit', 'id', (string)$old);

        $row = Db::gallery($old);
        $this->assertSame(array('0', null), array((string)$row['deleted'], $row['lastaudit']));
        $this->assertNull(Db::galleryByExhenid(4313473), 'the newer version is not queued');
    }

    public function testMissingGalleryIsMarkedAudited(): void
    {
        Stack::fake()->removeGallery(self::GID); // 404

        $this->runTask('Audit');

        $this->assertNotNull(Db::gallery($this->id)['lastaudit']);
    }

    public function testAuditSelection(): void
    {
        // added today: not audited yet
        Db::exec('update gallery set added = now() where id = ?', array($this->id));
        $this->runTask('Audit');
        $this->assertNull(Db::gallery($this->id)['lastaudit']);

        // added two days ago: audited (daily for the first week)
        Db::exec('update gallery set added = now() - interval 2 day where id = ?', array($this->id));
        $this->runTask('Audit');
        $audited = Db::gallery($this->id)['lastaudit'];
        $this->assertNotNull($audited);

        // audited just now: skipped
        Stack::fake()->clearRequests();
        $this->runTask('Audit');
        $this->assertSame(array(), Stack::fake()->requests());
    }

    public function testAuditUpdatesTypeButForceAuditDoesNot(): void
    {
        Db::exec("update gallery set type = 'Manga' where id = ?", array($this->id));
        $this->runTask('ForceAudit', 'id', (string)$this->id);
        $this->assertSame('Manga', Db::gallery($this->id)['type']);

        Db::exec('update gallery set lastaudit = null where id = ?', array($this->id)); // due again
        $this->runTask('Audit');
        $this->assertSame('Doujinshi', Db::gallery($this->id)['type']);
    }

    public function testForceAuditModes(): void
    {
        $this->runTask('ForceAudit', 'all');
        $this->assertNotNull(Db::gallery($this->id)['lastaudit']);

        Db::exec('update gallery set lastaudit = null');
        Stack::fake()->clearRequests();
        $this->runTask('ForceAudit', 'unknownmode');
        $this->assertSame(array(), Stack::fake()->requests(), 'unknown mode does nothing');
    }
}
