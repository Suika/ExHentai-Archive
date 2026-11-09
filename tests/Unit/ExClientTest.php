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

    public function testBanExpiry(): void
    {
        $page = 'Your IP address has been temporarily banned for excessive pageloads which indicates that you are '
            .'using automated mirroring/harvesting software. The ban expires in %s';

        $this->assertSame('2 hours and 13 minutes', \ExClient::banExpiry(sprintf($page, '2 hours and 13 minutes')));
        $this->assertSame('59 minutes and 48 seconds', \ExClient::banExpiry(sprintf($page, '59 minutes and 48 seconds')));
        $this->assertSame('13 seconds', \ExClient::banExpiry(sprintf($page, '13 seconds')));
        $this->assertSame('1 day, 2 hours and 5 minutes', \ExClient::banExpiry(sprintf($page, '1 day, 2 hours and 5 minutes')));
        $this->assertNull(\ExClient::banExpiry('Your IP address has been temporarily banned.'));
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
