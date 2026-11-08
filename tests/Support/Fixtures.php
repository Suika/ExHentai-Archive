<?php

namespace Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Saved ExHentai pages in tests/fixtures/exhentai (see tests/README.md for how to add them).
 */
final class Fixtures
{
    public static function dir(): string
    {
        return APP_ROOT.'/tests/fixtures/exhentai';
    }

    public static function path(string $name): string
    {
        return self::dir().'/'.$name;
    }

    public static function has(string $name): bool
    {
        return is_file(self::path($name));
    }

    /**
     * Returns the fixture's HTML, or skips the test when the page has not been saved yet.
     */
    public static function html(string $name): string
    {
        if (!self::has($name)) {
            TestCase::markTestSkipped("Fixture tests/fixtures/exhentai/$name is missing (see tests/README.md)");
        }

        return file_get_contents(self::path($name));
    }

    public static function requireAll(string ...$names): void
    {
        foreach ($names as $name) {
            self::html($name);
        }
    }

    /** @return string[] file names of all saved gallery pages (gallery-*.html) */
    public static function galleryPages(): array
    {
        return array_map('basename', glob(self::dir().'/gallery-*.html') ?: array());
    }
}
