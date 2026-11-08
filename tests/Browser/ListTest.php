<?php

namespace Tests\Browser;

use PHPUnit\Framework\TestCase;
use Tests\Support\Browser;
use Tests\Support\BrowserSeed;

/**
 * Gallery list and search box in a real browser.
 */
final class ListTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        BrowserSeed::create();
    }

    public function testListAndInfiniteScroll(): void
    {
        $b = Browser::desktop()->open('/');

        $b->waitFor('$(".gallery-list .gallery-item").length === 24');
        $this->assertSame('Browser Test Gallery', $b->js('$(".gallery-list .gallery-item .title").first().text().replace(/^\[\w+\] /, "")'));

        $b->js('window.scrollTo(0, document.body.scrollHeight)');
        $b->waitFor('$(".gallery-list .gallery-item").length === 31', 8000, 'second page loads when scrolled down');

        $this->assertSame(array(), $b->errors());
        $b->close();
    }

    public function testSuggestionsWithSpecialCharacters(): void
    {
        $b = Browser::desktop()->open('/');
        $b->waitFor('$(".gallery-list .gallery-item").length > 0');

        // the typed text was used as a regular expression to highlight it: "(" made it invalid (B19)
        $b->js('$(".search").focus()');
        $b->type('test (');
        $b->waitFor('$(".suggestions li").length > 0', 5000, 'suggestions are shown');

        $this->assertSame('test (series)', $b->js('$(".suggestions li").first().text()'));
        $this->assertSame('test (', $b->js('$(".suggestions li .highlight").first().text()'));
        $this->assertSame(array(), $b->errors());
        $b->close();
    }

    public function testSuggestionPickedWithKeyboard(): void
    {
        $b = Browser::desktop()->open('/');
        $b->waitFor('$(".gallery-list .gallery-item").length > 0');

        $b->js('$(".search").focus()');
        $b->type('stock');
        $b->waitFor('$(".suggestions li").length > 0');
        $b->key('ArrowDown');
        $b->key('Enter');

        $b->waitFor('$(".search").val() === "stockings"', 3000, 'the suggestion replaces the typed word');
        $b->waitFor('$(".gallery-list .gallery-item").length === 1', 5000, 'and the search runs');
        $this->assertSame(array(), $b->errors());
        $b->close();
    }

    public function testSuggestionsAreText(): void
    {
        $b = Browser::desktop()->open('/');
        $b->waitFor('$(".gallery-list .gallery-item").length > 0');

        $b->js('$(".search").focus()');
        $b->type('bol');
        $b->waitFor('$(".suggestions li").length > 0');

        $this->assertSame(0, $b->js('$(".suggestions li b").length'), 'tag names are not HTML (B19)');
        $this->assertStringContainsString('<b>bold</b>', $b->js('$(".suggestions li").first().text()'));
        $b->close();
    }

    public function testPhoneListShowsCoversOnly(): void
    {
        $b = Browser::phone()->open('/');
        $b->waitFor('$(".gallery-list .gallery-item").length > 0');

        $this->assertFalse($b->js('$(".gallery-list .gallery-item .top").first().is(":visible")'), 'no title bar');
        $this->assertFalse($b->js('$(".gallery-list .gallery-item .bottom").first().is(":visible")'), 'no tag/date bar');
        $this->assertSame(2, $b->js('Math.round(window.innerWidth / $(".gallery-list .gallery-item").first().outerWidth())'), 'two columns');
        $this->assertSame(0, $b->js('document.documentElement.scrollWidth - window.innerWidth'), 'no horizontal scrolling');
        $b->close();
    }
}
