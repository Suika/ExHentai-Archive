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
        Stack::fake()->gallery(self::GID, 'gallery-removed.html');

        $output = $this->runTask('Audit');

        $this->assertStringContainsString('Gallery was either removed or was invalid', $output);
        $row = Db::gallery($this->id);
        $this->assertNotNull($row['lastaudit']);
        $this->assertSame('0', (string)$row['deleted']);
        $this->assertSame($tags, $this->tags(), 'tags are kept');
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
