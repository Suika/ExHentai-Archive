<?php

namespace Tests\Support;

/**
 * Data for the browser tests: one real gallery (5 pages, see ZipFactory::standardEntries) plus enough
 * extra rows for more than one page of the list (24 per page).
 */
final class BrowserSeed
{
    /** @return int id of the real gallery (newest, first in the list) */
    public static function create(int $extra = 30): int
    {
        Stack::reset();

        $id = Stack::seedManualGallery('Browser Test Gallery', ZipFactory::standardEntries(),
            array('female:stockings', 'female:<b>bold</b>', 'artist:tester', 'parody:test (series)'));
        Db::exec("update gallery set posted = '2026-01-01 00:00:00' where id = ?", array($id));

        // list-only rows (no archive): enough for infinite scroll
        for ($i = 1; $i <= $extra; $i++) {
            Db::exec("insert into gallery (name, archived, added, posted, numfiles, filesize, source, deleted, download, hasmeta, updated)
                values (?, 1, now(), '2025-01-01 00:00:00' - interval ? day, 5, 1000, 1, 0, 1, 1, now() - interval 1 minute)",
                array(sprintf('Filler Gallery %02d', $i), $i));
        }

        Stack::reindex();

        return $id;
    }
}
