<?php

namespace Tests\Browser;

use PHPUnit\Framework\TestCase;
use Tests\Support\Browser;
use Tests\Support\BrowserSeed;
use Tests\Support\Stack;

/**
 * The reader: single page (spv) and multi page (mpv), desktop and phone.
 */
final class ReaderTest extends TestCase
{
    private static int $gallery;

    public static function setUpBeforeClass(): void
    {
        self::$gallery = BrowserSeed::create(0);
    }

    private function openSpv(Browser $b, int $index = 0): Browser
    {
        $b->open('/?action=gallery&id='.self::$gallery.'&index='.$index, array('viewType' => 'spv'));
        $b->waitFor('$(".image-holder").prop("complete") && $(".image-holder").prop("naturalWidth") > 0'
            .' && /index='.$index.'(&|$)/.test($(".image-holder").attr("src"))');

        return $b;
    }

    private function openMpv(Browser $b): Browser
    {
        $b->open('/?action=gallery&id='.self::$gallery.'&index=0', array('viewType' => 'mpv'));
        $b->waitFor('$(".pages-container .page.loaded").length >= 2');

        return $b;
    }

    private static function spvIndex(Browser $b): ?int
    {
        $src = $b->js('$(".image-holder").attr("src") || ""');
        return preg_match('/index=(\d+)/', $src, $m) ? (int)$m[1] : null;
    }

    public function testSpvKeyboard(): void
    {
        $b = $this->openSpv(Browser::desktop());
        $this->assertSame('1/5', $b->js('$(".page-count").text()'));

        $b->key('ArrowRight');
        $b->waitFor('$(".page-count").text() === "2/5"');
        $this->assertSame(1, self::spvIndex($b));

        $b->key('ArrowLeft');
        $b->waitFor('$(".page-count").text() === "1/5"');
        $this->assertSame(0, self::spvIndex($b));

        $b->key('Escape');
        $b->waitFor('!$("html").hasClass("reader-active")');

        $this->assertSame(array(), $b->errors());
        $b->close();
    }

    public function testSpvPreloadsOnlyAFewPages(): void
    {
        $b = $this->openSpv(Browser::desktop());
        usleep(1500000);

        $indexes = array_unique(array_map(fn ($u) => preg_match('/index=(\d+)/', $u, $m) ? (int)$m[1] : -1,
            $b->pageImageRequests()));
        sort($indexes);
        $this->assertSame(array(0, 1, 2, 3), $indexes, 'the current page and 3 ahead');
        $b->close();
    }

    public function testMpvKeyboardScrollsToThePage(): void
    {
        $b = $this->openMpv(Browser::desktop());

        $b->key('ArrowRight');
        $b->waitFor('$(".page-count").text() === "2/5"');
        $b->waitFor('Math.abs($(".pages-container .page")[1].getBoundingClientRect().top) <= 25', 3000,
            'page 2 is scrolled to the top (20px page gap)');
        $b->close();
    }

    public function testDeleteEnabled(): void
    {
        $b = $this->openSpv(Browser::desktop());

        $this->assertSame(1, $b->js('$(".actions-menu li[data-action=delete]").length'));
        $this->assertNotSame('none', $b->js('$(".actions-menu li[data-action=delete]").css("display")'));
        $b->close();
    }

    public function testDeleteHiddenWhenDisabled(): void
    {
        $restore = Stack::config(array('allowDelete' => false));
        try {
            $b = $this->openSpv(Browser::desktop());
            $this->assertSame('none', $b->js('$(".actions-menu li[data-action=delete]").css("display")'));
            $b->close();
        } finally {
            $restore();
        }
    }

    public function testPhoneSpvTapAndSwipe(): void
    {
        $b = $this->openSpv(Browser::phone());

        $b->tap(350, 420); // right third
        $b->waitFor('$(".page-count").text() === "2/5"', 3000, 'tap on the right side turns the page');

        $b->tap(40, 420); // left third
        $b->waitFor('$(".page-count").text() === "1/5"', 3000, 'tap on the left side goes back');

        $b->swipe(330, 420, 60, 430); // swipe left
        $b->waitFor('$(".page-count").text() === "2/5"', 3000, 'swipe left turns the page');

        $b->swipe(60, 420, 330, 430); // swipe right
        $b->waitFor('$(".page-count").text() === "1/5"', 3000, 'swipe right goes back');

        $this->assertSame(array(), $b->errors());
        $b->close();
    }

    public function testPhoneTapShowsReaderControls(): void
    {
        $b = $this->openSpv(Browser::phone());

        $this->assertFalse($b->js('$(".gallery-info .tags").is(":visible")'));

        $b->tap(195, 420); // middle
        $b->waitFor('$(".gallery-info .tags").is(":visible") && $(".gallery-actions").is(":visible")', 3000,
            'tap in the middle shows title, tags and actions');

        $this->assertGreaterThanOrEqual(388, $b->js('$(".gallery-info").outerWidth()'), 'title and tags use the full width');
        $this->assertGreaterThan($b->js('$(".gallery-actions").offset().top + $(".gallery-actions").outerHeight()'),
            $b->js('$(".gallery-info").offset().top'), 'below the page counter and actions');
        $this->assertGreaterThanOrEqual(28, $b->js('$(".gallery-info .tag").first().outerHeight()'), 'tags are big enough to tap');

        $b->tap(195, 420);
        $b->waitFor('!$(".gallery-info .tags").is(":visible")', 3000, 'a second tap hides them');
        $this->assertSame('1/5', $b->js('$(".page-count").text()'), 'middle taps do not turn pages');
        $b->close();
    }

    public function testPhoneMpvSharpImagesAndThumbnails(): void
    {
        $b = $this->openMpv(Browser::phone());

        $src = $b->js('$(".pages-container .page img").first().attr("src")');
        $this->assertMatchesRegularExpression('/resize=(\d+)/', $src);
        preg_match('/resize=(\d+)/', $src, $m);
        $this->assertGreaterThanOrEqual(390 * 3, (int)$m[1], 'width in device pixels');

        $b->tap(195, 420);
        $b->waitFor('$(".gallery-thumbs-outer").offset().left >= 0 && $(".gallery-thumbs-outer .gallery-thumb").length === 5',
            3000, 'a tap shows the thumbnail strip');
        $b->js('$(".gallery-thumbs-outer .gallery-thumb").eq(3).click()');
        $b->waitFor('$(".page-count").text() === "4/5"', 3000, 'a thumbnail jumps to its page');
        $b->close();
    }
}
