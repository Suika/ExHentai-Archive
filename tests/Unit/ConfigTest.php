<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ConfigOverride;

/**
 * config.json: "base" values, overridden by the entry for this host name or "default", with "inherits".
 */
final class ConfigTest extends TestCase
{
    private string $cwd;
    private string $dir;

    protected function setUp(): void
    {
        $this->cwd = getcwd();
        $this->dir = sys_get_temp_dir().'/configtest-'.getmypid();
        @mkdir($this->dir);
        chdir($this->dir);
        ConfigOverride::set(null);
    }

    protected function tearDown(): void
    {
        chdir($this->cwd);
        @unlink($this->dir.'/config.json');
        @rmdir($this->dir);
        ConfigOverride::set(null);
    }

    private function write(array $data): void
    {
        file_put_contents($this->dir.'/config.json', json_encode($data));
    }

    public function testDefaultOverridesBase(): void
    {
        $this->write(array(
            'base' => array('archiveDir' => '/base', 'viewType' => 'mpv'),
            'default' => array('archiveDir' => '/default', 'db' => array('user' => 'u')),
        ));

        $config = \Config::get();
        $this->assertSame('/default', $config->archiveDir);
        $this->assertSame('mpv', $config->viewType);
        $this->assertSame('u', $config->db->user);
    }

    public function testHostEntryWinsOverDefaultAndInherits(): void
    {
        $this->write(array(
            'base' => array('a' => 'base', 'b' => 'base', 'c' => 'base'),
            'default' => array('a' => 'default'),
            'shared' => array('b' => 'shared', 'c' => 'shared'),
            gethostname() => array('inherits' => 'shared', 'c' => 'host'),
        ));

        $config = \Config::get();
        $this->assertSame('base', $config->a, '"default" is not used when the host has an entry');
        $this->assertSame('shared', $config->b);
        $this->assertSame('host', $config->c);
    }

    public function testBuildCookie(): void
    {
        $this->write(array(
            'base' => array('cookie' => array('ipb_member_id' => '1', 'ipb_pass_hash' => 'abc', 'sp' => '2')),
        ));

        \Config::get();
        $this->assertSame('ipb_member_id=1; ipb_pass_hash=abc; sp=2', \Config::buildCookie());
    }
}
