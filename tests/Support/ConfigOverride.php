<?php

namespace Tests\Support;

/**
 * Sets or clears the cached Config::get() result for unit tests (no config.json needed).
 */
final class ConfigOverride
{
    public static function set(?object $config): void
    {
        $prop = new \ReflectionProperty(\Config::class, 'config');
        $prop->setValue(null, $config);
    }
}
