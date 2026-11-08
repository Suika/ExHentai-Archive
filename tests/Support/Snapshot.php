<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Golden files in tests/snapshots. A missing snapshot is written and the test is marked incomplete;
 * UPDATE_SNAPSHOTS=1 rewrites all snapshots (review the git diff afterwards).
 */
final class Snapshot
{
    public static function assertMatches(string $name, $data): void
    {
        $file = APP_ROOT.'/tests/snapshots/'.$name.'.json';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        if (getenv('UPDATE_SNAPSHOTS') === '1' || !is_file($file)) {
            $existed = is_file($file);
            if (!is_dir(dirname($file))) {
                mkdir(dirname($file), 0777, true);
            }
            file_put_contents($file, $json);

            if (!$existed) {
                Assert::markTestIncomplete("Snapshot created: tests/snapshots/$name.json, review and commit it");
            }
            return;
        }

        Assert::assertSame(file_get_contents($file), $json, "Snapshot tests/snapshots/$name.json differs");
    }

    /**
     * Removes values that change from run to run (timings, cache flags, timestamps of the test run) and
     * the archive size (depends on the image encoder of the build, not on the app).
     */
    public static function normalize($data, array $drop = array('time', 'fromcache', 'added', 'updated', 'lastaudit', 'filesize'))
    {
        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, $drop, true)) {
                unset($data[$key]);
            } else {
                $data[$key] = self::normalize($value, $drop);
            }
        }

        return $data;
    }
}
