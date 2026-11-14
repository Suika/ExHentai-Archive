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
        $this->assertSame('3 hours and 37 minutes', \ExClient::banExpiry(self::CURRENT_BAN_PAGE));
    }

    /** ExHentai's ban page as of 2026-11 (HTTP 200, plain text) */
    private const CURRENT_BAN_PAGE = 'This IP address has been temporarily banned due to an excessive request rate. '
        .'This probably means you are using automated mirroring/harvesting software or share the IP address with '
        .'someone who does. The ban expires in 3 hours and 37 minutes';

    public function testBanMessage(): void
    {
        $this->assertSame('IP temporarily banned by ExHentai, expires in 3 hours and 37 minutes',
            \ExClient::banMessage(self::CURRENT_BAN_PAGE));
        $this->assertSame('IP temporarily banned by ExHentai, expires in 2 hours and 13 minutes',
            \ExClient::banMessage('Your IP address has been temporarily banned for excessive pageloads which indicates '
                .'that you are using automated mirroring/harvesting software. The ban expires in 2 hours and 13 minutes'),
            'the older wording');

        $this->assertSame('IP temporarily banned by ExHentai: "This IP address has been temporarily banned. The ban expires soon."',
            \ExClient::banMessage("<p>This IP address has been temporarily banned.</p>\n<p>The ban expires soon.</p>"),
            'no time found: the server text');

        $this->assertNull(\ExClient::banMessage(\Tests\Support\Fixtures::html('gallery-4302753.html')));
        $this->assertNull(\ExClient::banMessage('<html><body>You are opening pages too fast, thus placing a heavy load on the '
            .'server. Back down, or your IP address will be automatically banned.</body></html>'));
        $this->assertNull(\ExClient::banMessage(false));
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
