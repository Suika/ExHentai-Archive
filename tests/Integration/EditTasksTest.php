<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\Api;
use Tests\Support\Db;
use Tests\Support\Stack;
use Tests\Support\ZipFactory;

/**
 * AddGallery / EditGallery and the TaskRunner itself.
 */
final class EditTasksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        Stack::reset();
    }

    public function testAddGallery(): void
    {
        $id = Stack::seedManualGallery('Added By Hand', ZipFactory::standardEntries());

        $gallery = Api::data('gallery', array('id' => $id));
        $this->assertSame(array('Added By Hand', '1', '1', '5'),
            array($gallery['name'], (string)$gallery['source'], (string)$gallery['archived'], (string)$gallery['numfiles']));
        $this->assertFileExists(APP_ROOT.'/archive/galleries/1-'.$id.'.zip');

        $ids = array_map(fn ($g) => (int)$g['id'], Api::data('galleries', array('search' => 'added by hand'))['galleries']);
        $this->assertSame(array($id), $ids, 'AddGallery reindexes');
    }

    public function testAddGalleryUsage(): void
    {
        $result = Stack::task('AddGallery', 'only-one-argument');

        $this->assertStringContainsString('Example: TaskRunner AddGallery input.zip galleryname', $result['output']);
    }

    public function testEditGalleryAddTag(): void
    {
        $id = Stack::seedManualGallery('Tag Me', ZipFactory::standardEntries(), array('female:glasses'));

        $result = Stack::task('EditGallery', (string)$id, 'addtag', 'female:glasses', 'parody:original', 'notag');

        $this->assertStringContainsString('Gallery already has tag: female:glasses', $result['output']);
        $this->assertStringContainsString('Missing tag namespace: notag', $result['output']);
        $this->assertSame(array('female' => array('glasses'), 'parody' => array('original')),
            Api::data('gallery', array('id' => $id))['tags']);
    }

    public function testUnknownTask(): void
    {
        $result = Stack::task('NoSuchTask');

        $this->assertStringContainsString('Failed to load class: Task_NoSuchTask', $result['output']);
    }

    public function testTaskList(): void
    {
        exec('cd '.escapeshellarg(APP_ROOT).' && php TaskRunner.php', $out);

        $this->assertContains(' * AddGallery.php', $out);
        $this->assertContains(' * Archive.php', $out);
    }

    public function testRunningTaskIsNotStartedTwice(): void
    {
        $pidFile = APP_ROOT.'/tmp/Task_Cleanup.pid';
        file_put_contents($pidFile, '1'); // pid 1 always exists

        $result = Stack::task('Cleanup');
        unlink($pidFile);

        $this->assertStringContainsString('Task already running: Task_Cleanup', $result['output']);
    }

    public function testGalleryCountUnchangedByFailedTasks(): void
    {
        $this->assertSame(0, Db::count('gallery', 'name is null and exhenid is null'));
    }
}
