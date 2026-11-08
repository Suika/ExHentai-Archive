<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Task_Audit::tagsDiff decides whether an audit rewrites a gallery's tags.
 */
final class AuditTagsDiffTest extends TestCase
{
    private const TAGS = array(
        'artist' => array('someone'),
        'female' => array('glasses', 'stockings'),
    );

    public function testSameTagsGiveNoDifference(): void
    {
        $this->assertSame(array(), \Task_Audit::tagsDiff(self::TAGS, self::TAGS));
    }

    public function testOrderDoesNotMatter(): void
    {
        $new = array('female' => array('stockings', 'glasses'), 'artist' => array('someone'));
        $this->assertSame(array(), \Task_Audit::tagsDiff(self::TAGS, $new));
    }

    public function testAddedTag(): void
    {
        $new = self::TAGS;
        $new['female'][] = 'twintails';

        $this->assertSame(array('female' => array('twintails')), \Task_Audit::tagsDiff(self::TAGS, $new));
    }

    public function testRemovedTag(): void
    {
        $new = self::TAGS;
        $new['female'] = array('glasses');

        $this->assertSame(array('female' => array('stockings')), \Task_Audit::tagsDiff(self::TAGS, $new));
    }

    public function testNewAndRemovedNamespace(): void
    {
        $new = array('female' => self::TAGS['female'], 'language' => array('english'));

        $this->assertSame(
            array('artist' => array('someone'), 'language' => array('english')),
            \Task_Audit::tagsDiff(self::TAGS, $new)
        );
    }

    public function testEmptyOldTags(): void
    {
        $this->assertSame(self::TAGS, \Task_Audit::tagsDiff(array(), self::TAGS));
    }
}
