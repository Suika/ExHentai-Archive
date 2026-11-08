<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Search box semantics: how user input is turned into a full text MATCH() query.
 * A search backend change (D29) has to keep (or deliberately change) these.
 */
final class SearchEscapeTest extends TestCase
{
    public static function cases(): array
    {
        return array(
            'lower case' => array('Artist Name', 'artist name'),
            'namespace and phrase' => array('female:"big breasts"', 'female:"big breasts"'),
            'hyphenated word becomes a phrase' => array('tag-name', '"tag\\-name"'),
            'leading minus excludes' => array('-excluded', '-excluded'),
            'or operator' => array('a|b', 'a|b'),
            'unbalanced quote is closed' => array('"unbalanced', '"unbalanced"'),
            'brackets and bang escaped' => array('C++ (test)!', 'c++ \\(test\\)\\!'),
            'at escaped' => array('foo@bar', 'foo\\@bar'),
            'unicode lower case' => array('ÄÖÜ Großbuchstaben', 'äöü großbuchstaben'),
            'slash escaped' => array('x/y', 'x\\/y'),
            'operators escaped' => array('a=b^c$', 'a\\=b\\^c\\$'),
            'backslash escaped' => array('back\\\\slash', 'back\\\\\\\\slash'),
            // quirk: a quoted hyphenated word ends up with a double backslash
            'quoted hyphenated word' => array('"tag-name"', '"tag\\\\-name"'),
            'proximity and and escaped' => array('~near&far', '\\~near\\&far'),
            'whitespace kept' => array('  spaced  ', '  spaced  '),
        );
    }

    #[DataProvider('cases')]
    public function testHalfEscapeMatch(string $input, string $expected): void
    {
        $this->assertSame($expected, \SphinxQL::halfEscapeMatch($input));
    }
}
