<?php

/**
 * Search index maintenance.
 *
 * With "indexer.triggerDir" configured (Docker), rebuilds are only requested: a trigger file is created
 * and the reindex worker (docker/reindexer.sh, root) does the work in the background. Without it the
 * configured command "indexer.full" is run directly, as before.
 */
class Search
{
    /**
     * @param bool $full true: rebuild everything (e.g. after galleries were removed),
     *                   false: only galleries changed since the last indexing
     */
    public static function requestReindex($full = false)
    {
        $indexer = Config::get()->indexer ?? null;

        if (!empty($indexer->triggerDir)) {
            $file = rtrim($indexer->triggerDir, '/').'/reindex-'.($full ? 'full' : 'delta').'.request';
            if (!file_exists($file)) {
                @touch($file);
            }
            return;
        }

        if (!empty($indexer->full)) {
            system($indexer->full);
        }
    }

    /**
     * Changes attributes of a gallery in the live search index right away (read status, colour, deleted),
     * so filters don't wait for the next indexing. MariaDB stays the source of truth: the next indexing
     * of the gallery gives the same values.
     *
     * @param array $values attribute => value
     */
    public static function updateAttributes($galleryId, array $values)
    {
        $set = array();
        $params = array();
        foreach ($values as $name => $value) {
            $set[] = $name.' = ?';
            // explicit types: RedBean binds numeric strings like "1" as integers, which Manticore accepts for a
            // string attribute ("read") without storing the value
            $params[] = is_int($value) ? array($value, PDO::PARAM_INT) : array((string)$value, PDO::PARAM_STR);
        }
        $params[] = array((int)$galleryId, PDO::PARAM_INT);

        try {
            SphinxQL::exec('UPDATE galleries SET '.implode(', ', $set).' WHERE id = ?', $params);
        } catch (Throwable $e) {
            error_log('Search: updating the index directly failed, requesting a reindex: '.$e->getMessage());
            self::requestReindex();
        }
    }
}
