<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ConfigOverride;

/**
 * The "exhentai" config seam that points ExClient at the fake server in the integration tests.
 */
final class ExClientTest extends TestCase
{
    protected function tearDown(): void
    {
        ConfigOverride::set(null);
    }

    private static function url(string $url): string
    {
        $method = new \ReflectionMethod(\ExClient::class, 'url');
        return $method->invoke(new \ExClient(), $url);
    }

    public function testUrlsUnchangedByDefault(): void
    {
        ConfigOverride::set((object)array());

        $this->assertSame('https://exhentai.org/g/1/abc/?p=0', self::url('https://exhentai.org/g/1/abc/?p=0'));
    }

    public function testBaseUrlReplacesExhentai(): void
    {
        ConfigOverride::set((object)array('exhentai' => (object)array('baseUrl' => 'http://127.0.0.1:8090/')));

        $this->assertSame('http://127.0.0.1:8090/g/1/abc/?p=0', self::url('https://exhentai.org/g/1/abc/?p=0'));
        $this->assertSame(
            'http://127.0.0.1:8090/archiver.php?gid=1&token=abc',
            self::url('https://exhentai.org/archiver.php?gid=1&token=abc'),
            'links taken from pages are redirected too'
        );
        $this->assertSame('https://s.exhentai.org/w/1.webp', self::url('https://s.exhentai.org/w/1.webp'),
            'other hosts are left alone');
    }
}
