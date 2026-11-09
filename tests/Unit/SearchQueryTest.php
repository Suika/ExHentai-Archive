<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Search box semantics: how user input is turned into an FTS5 query (classes/Search.php).
 * Every term is quoted, so no input can be an FTS5 syntax error.
 */
final class SearchQueryTest extends TestCase
{
    public static function cases(): array
    {
        return array(
            'words are all required' => array('Artist Name', '"artist" AND "name"', null),
            'tag' => array('female:stockings', '"female:stockings"', null),
            'quoted tag with spaces' => array('"female:big breasts"', '"female:big breasts"', null),
            'namespace before a phrase' => array('female:"big breasts"', '"female:big breasts"', null),
            'phrase' => array('"chastity belt"', '"chastity belt"', null),
            'unbalanced quote' => array('"unbalanced', '"unbalanced"', null),
            'exclusion' => array('stockings -tomgirl', '("stockings") NOT ("tomgirl")', null),
            'excluded phrase' => array('a1 -"big breasts"', '("a1") NOT ("big breasts")', null),
            'only exclusions' => array('-tomgirl -futa', null, '"tomgirl" OR "futa"'),
            'or binds tighter than and' => array('english a1 | b2', '"english" AND ("a1" OR "b2")', null),
            'or without spaces' => array('a1|b2|c3', '("a1" OR "b2" OR "c3")', null),
            'hyphenated word' => array('x-ray', '"x-ray"', null),
            'single letters are ignored' => array('vol 2 x', '"vol"', null),
            'operators are only text' => array('NEAR(a b) OR cc* ^d', '"near(a" AND "or" AND "cc*"', null),
            'quote inside a word' => array('it"s', '"it" AND "s"', null),
            'kanji split into characters' => array('東方', '"東  方"', null),
            'nothing searchable' => array(' - | "" $ ', null, null),
            'unicode lower case' => array('ÄÖÜ', '"äöü"', null),
            'number signs separate' => array('NTR²', '"ntr"', null),
        );
    }

    #[DataProvider('cases')]
    public function testMatchQuery(string $input, ?string $match, ?string $exclude): void
    {
        $this->assertSame(array('match' => $match, 'exclude' => $exclude), \Search::matchQuery($input));
    }

    public function testNormalizeKeepsColonsOnlyInsideWords(): void
    {
        $this->assertSame('Re  Zero female:big breasts  3', \Search::normalize('Re: Zero female:big breasts :3'));
    }
}
