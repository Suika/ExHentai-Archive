<?php

/**
 * Full text search with SQLite FTS5 (table gallery_fts in db.sql, rowid = gallery.id).
 *
 * The index only holds the searchable text of a gallery (name, original title, type, ExHentai id, tags).
 * It is updated whenever a gallery is stored (Model_Gallery::after_update) or removed
 * (Model_Gallery::delete); filters and sorting read the gallery table directly (Model_Gallery::search).
 *
 * The words behave like in the former Manticore index:
 * - namespaced tags are one word ("female:stockings"), and their parts are words too ("female", "stockings")
 * - kanji, hiragana and katakana are single-character words, so parts of Japanese titles are found
 */
class Search
{
    /** kanji, hiragana, katakana: indexed character by character (Manticore ngram_chars, ngram_len = 1) */
    const CJK = '\x{4E00}-\x{9FBF}\x{3040}-\x{309F}\x{30A0}-\x{30FF}';

    /** characters of a word, as the FTS5 tokenizer sees them (unicode61 plus tokenchars ':' and '_') */
    const WORD = '\p{L}\p{N}\p{Co}_';

    /**
     * Writes the index row of a gallery (after it was stored with its tags).
     */
    public static function indexGallery($galleryId)
    {
        $row = R::getRow('select id, name, origtitle, type, exhenid from gallery where id = ?', array($galleryId));
        if (!$row) {
            return;
        }

        $tags = R::getAll(
            'select tag.name as tag, tagnamespace.name as ns from gallery_tag '.
            'join tag on tag.id = gallery_tag.tag_id '.
            'left join tagnamespace on tagnamespace.id = gallery_tag.namespace_id '.
            'where gallery_tag.gallery_id = ? order by gallery_tag.id',
            array($galleryId)
        );

        self::write($row, $tags);
    }

    public static function removeGallery($galleryId)
    {
        R::exec('delete from gallery_fts where rowid = ?', array((int)$galleryId));
    }

    /**
     * Rebuilds the whole index (migration, api.php?action=indexer) in one transaction (prod data: about
     * 30 s). Searches keep using the old index meanwhile; other writes (tasks, read status) wait for it
     * (busy_timeout in common.php).
     *
     * @return int indexed galleries
     */
    public static function rebuild()
    {
        // IMMEDIATE: take the write lock first (a transaction that reads first can't wait for it later)
        R::exec('BEGIN IMMEDIATE');
        try {
            R::exec('delete from gallery_fts');

            $count = 0;
            $lastId = 0;
            while (true) {
                $rows = R::getAll('select id, name, origtitle, type, exhenid from gallery where id > ? order by id limit 1000', array($lastId));
                if (!$rows) {
                    break;
                }

                $ids = array_column($rows, 'id');
                $tagsByGallery = array();
                $tagRows = R::getAll(
                    'select gallery_tag.gallery_id as gallery, tag.name as tag, tagnamespace.name as ns from gallery_tag '.
                    'join tag on tag.id = gallery_tag.tag_id '.
                    'left join tagnamespace on tagnamespace.id = gallery_tag.namespace_id '.
                    'where gallery_tag.gallery_id between ? and ? order by gallery_tag.id',
                    array(reset($ids), end($ids))
                );
                foreach ($tagRows as $tagRow) {
                    $tagsByGallery[$tagRow['gallery']][] = $tagRow;
                }

                foreach ($rows as $row) {
                    self::write($row, $tagsByGallery[$row['id']] ?? array(), false);
                    $count++;
                }
                $lastId = end($ids);
            }

            R::exec('COMMIT');
        } catch (Throwable $e) {
            R::exec('ROLLBACK');
            throw $e;
        }

        return $count;
    }

    /**
     * @param array $row gallery columns id, name, origtitle, type, exhenid
     * @param array $tags rows with "tag" and "ns" (namespace)
     */
    protected static function write(array $row, array $tags, $replace = true)
    {
        $words = array();
        $namespaces = array();
        foreach ($tags as $tag) {
            $words[] = $tag['tag'];
            if ($tag['ns'] !== null && $tag['ns'] !== '') {
                $words[] = $tag['ns'].':'.$tag['tag'];
                $namespaces[$tag['ns']] = true;
            }
        }

        $fields = array(
            'name' => self::normalize($row['name']),
            'origtitle' => self::normalize($row['origtitle']),
            'type' => self::normalize($row['type']),
            'exhenid' => (string)$row['exhenid'],
            'tags' => self::normalize(implode(', ', $words)),
        );

        // parts of words with ":": the namespaces of the tags ("female"; the tag itself is in "tags"
        // already) and the parts of such words in the other fields ("Re:Zero" -> "re zero")
        $parts = array_keys($namespaces);
        foreach (array('name', 'origtitle', 'type') as $field) {
            if (preg_match_all('/['.self::WORD.':]*:['.self::WORD.':]*/u', $fields[$field], $m)) {
                foreach ($m[0] as $word) {
                    $parts[] = str_replace(':', ' ', $word);
                }
            }
        }
        $fields['parts'] = self::normalize(implode(' ', $parts));

        if ($replace) {
            self::removeGallery($row['id']);
        }
        R::exec(
            'insert into gallery_fts (rowid, name, origtitle, type, exhenid, tags, parts) values (?, ?, ?, ?, ?, ?, ?)',
            array((int)$row['id'], $fields['name'], $fields['origtitle'], $fields['type'], $fields['exhenid'], $fields['tags'], $fields['parts'])
        );
    }

    /**
     * SQL functions used by searches:
     *   crc32(value)             random order per seed, as before (Manticore: crc32(to_string(id + seed)))
     *   wordprefix(text, prefix) 1 if a word of text starts with prefix (tag suggestions)
     */
    public static function registerFunctions()
    {
        static $pdo = null;
        $current = R::getDatabaseAdapter()->getDatabase()->getPDO();
        if ($pdo === $current) {
            return;
        }
        $pdo = $current;

        $functions = array(
            'crc32' => array(fn ($value) => crc32((string)$value), 1),
            'wordprefix' => array(fn ($text, $prefix) => (int)preg_match(
                '/(?<!['.self::WORD.'])'.preg_quote(mb_strtolower((string)$prefix), '/').'/u', mb_strtolower((string)$text)), 2),
        );
        foreach ($functions as $name => list($callback, $args)) {
            if (method_exists($pdo, 'createFunction')) { // Pdo\Sqlite
                $pdo->createFunction($name, $callback, $args, PDO::SQLITE_DETERMINISTIC);
            } else {
                $pdo->sqliteCreateFunction($name, $callback, $args, PDO::SQLITE_DETERMINISTIC);
            }
        }
    }

    /**
     * Text as it is indexed and searched: CJK characters as single words, ":" only inside words.
     */
    public static function normalize($text)
    {
        $text = (string)$text;
        $text = preg_replace('/(['.self::CJK.'])/u', ' $1 ', $text);
        // "Manga②", "NTR²": such number signs separate words, as in the former index
        $text = preg_replace('/\p{No}/u', ' ', $text);
        // "Re: Zero", ":3": a ":" at the start or end of a word is a separator
        $text = preg_replace('/(?<!['.self::WORD.']):|:(?!['.self::WORD.'])/u', ' ', $text);

        return $text;
    }

    /**
     * Turns the text of the search field into an FTS5 query. Supported, as before:
     *   words (all must match), "a phrase", -excluded, a | b (either), tag searches (female:stockings,
     *   "female:big breasts", female:"big breasts"). Words with "-" inside ("x-ray") are a phrase.
     * Every term is quoted, so no input can produce a syntax error. Words of one letter or digit are
     * ignored, as by the former index (min_word_len = 2); a search of nothing but such words finds nothing.
     *
     * @return array{match: ?string, exclude: ?string} match: what has to match; exclude: what must not
     *                                                  match (when there is nothing to match); both null:
     *                                                  no search, or nothing searchable (no results)
     */
    public static function matchQuery($input)
    {
        $input = mb_strtolower(trim((string)$input));

        // -"phrase" (also namespace:"phrase") | or | -word
        preg_match_all('/(-?)((?:[^\s"|:]+:)?)"([^"]*)"?|(\|)|(-?)([^\s"|]+)/u', $input, $tokens, PREG_SET_ORDER);

        $groups = array();   // AND of OR groups
        $exclude = array();
        $or = false;
        foreach ($tokens as $token) {
            if (isset($token[4]) && $token[4] === '|') {
                $or = true;
                continue;
            }

            if (isset($token[6])) {
                $negative = $token[5] === '-';
                $term = self::term($token[6], false);
            } else {
                $negative = $token[1] === '-';
                $term = self::term($token[2].$token[3], true);
            }

            if ($term !== null) {
                if ($negative) {
                    $exclude[] = $term;
                } elseif ($or && $groups) {
                    $groups[count($groups) - 1][] = $term;
                } else {
                    $groups[] = array($term);
                }
            }
            $or = false;
        }

        $and = array();
        foreach ($groups as $group) {
            $and[] = count($group) > 1 ? '('.implode(' OR ', $group).')' : $group[0];
        }

        $match = $and ? implode(' AND ', $and) : null;
        $not = $exclude ? implode(' OR ', $exclude) : null;

        if ($match !== null && $not !== null) {
            return array('match' => '('.$match.') NOT ('.$not.')', 'exclude' => null);
        }

        return array('match' => $match, 'exclude' => $not);
    }

    /**
     * One search term as a quoted FTS5 string, or null if it contains nothing searchable.
     */
    protected static function term($text, $phrase)
    {
        $text = trim(self::normalize($text));

        $words = preg_split('/[^'.self::WORD.':]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return null;
        }

        // a single letter or digit is not in the index (min_word_len = 2), except kanji/kana
        if (!$phrase && count($words) === 1 && mb_strlen($words[0]) === 1 && !preg_match('/['.self::CJK.']/u', $words[0])) {
            return null;
        }

        return '"'.str_replace('"', '""', $text).'"';
    }
}
