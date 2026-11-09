<?php

class Task_Audit extends Task_Abstract {

    const LOG_TAG = 'Task_Audit';

    protected $client;

    /** Whether an audit also refreshes the gallery type (Task_ForceAudit never did) */
    protected $updateType = true;

    /** a run stops after this many failed requests in a row (ExHentai or the network is down) */
    const MAX_FAILURES_IN_A_ROW = 5;

    public function run($options = array()) {
        $this->client = new ExClient();

        $interval = self::sqliteInterval(isset($options[0]) ? $options[0] : '1 year');
        if ($interval === null) {
            Log::error(static::LOG_TAG, 'Invalid interval "%s", expected e.g. "1 year", "6 months", "2 weeks" or "30 days"', $options[0]);
            return;
        }
        $skipped = array();   // galleries whose request failed: not marked, checked again by the next run
        $failuresInARow = 0;

        try {
            while(true) {
                // dates are compared in the app's time zone (galleries store PHP's local time)
                $sql = 'archived = 1 and deleted = 0 and source = 0 and'.
                    "((added <= datetime('now', 'localtime', 'start of day', '-3 days') and". // if added more than 3 days ago...
                    "(posted >= datetime('now', 'localtime', 'start of day', '$interval') or (lastaudit is null)) and". // and if the gallery is more than input old but NOT if it's unaudited
                    "(lastaudit is null or lastaudit <= datetime('now', 'localtime', 'start of day', '-7 days'))) or". // ...and not yet audited, or audited more than 7 days ago
                    "((added >= datetime('now', 'localtime', 'start of day', '-7 days') and added <= datetime('now', 'localtime', 'start of day', '-1 days')) and". // OR, added less than 7 days ago (but more than 24 hours ago)...
                    "(lastaudit is null or lastaudit <= datetime('now', 'localtime', 'start of day', '-1 days'))))"; // ...and not yet audited, or audited more than 1 day ago
                if ($skipped) {
                    $sql = '('.$sql.') and id not in ('.R::genSlots($skipped).')';
                }

                $galleries = R::find('gallery', $sql.' order by posted desc limit 100', $skipped);

                if(count($galleries) === 0) {
                    break;
                }

                foreach($galleries as $gallery) {
                    if ($this->audit($gallery)) {
                        $failuresInARow = 0;
                        continue;
                    }

                    $skipped[] = $gallery->id;
                    if (++$failuresInARow >= self::MAX_FAILURES_IN_A_ROW) {
                        Log::error(static::LOG_TAG, 'ExHentai not reachable (%d failed requests in a row), stopping this run', $failuresInARow);
                        break 2;
                    }
                }
            }
        } catch (ExClient_BannedException $e) {
            // retrying would only extend the ban: stop this run, the next one continues
            Log::error(static::LOG_TAG, '%s: stopped this run', $e->getMessage());
        }
    }

    /**
     * The interval option ("1 year", "6 months", "2 weeks", "30 day") as an SQLite date modifier
     * ("-1 years"), or null if it is not one.
     */
    protected static function sqliteInterval($interval)
    {
        if (!preg_match('/^\s*(\d+)\s*(day|week|month|year)s?\s*$/i', (string)$interval, $m)) {
            return null;
        }

        $count = (int)$m[1];
        $unit = strtolower($m[2]);
        if ($unit === 'week') {
            $count *= 7;
            $unit = 'day';
        }

        return sprintf('-%d %ss', $count, $unit);
    }

    /**
     * True when ExHentai answered, also with an error page (HTTP 404: removed gallery or copyright claim).
     * False when there was no answer (connection failure, timeout) or a server error (HTTP 5xx).
     */
    protected function answered($html) {
        if (is_string($html) && $html !== '' && $this->client->lastStatus < 500) {
            return true;
        }

        Log::error(static::LOG_TAG, 'Request failed (%s), the gallery is checked again by the next run',
            $this->client->lastError ?: 'HTTP '.$this->client->lastStatus);
        return false;
    }

    /**
     * @return bool false when ExHentai could not be asked (the gallery is left unchanged, not marked as audited)
     * @throws ExClient_BannedException
     */
    protected function audit($gallery) {
        Log::debug(static::LOG_TAG, 'Auditing gallery: #%d - %s', $gallery->exhenid, $gallery->name);

        $galleryHtml = $this->client->gallery($gallery->exhenid, $gallery->hash);
        if (!$this->answered($galleryHtml)) {
            return false;
        }

        $galleryPage = new ExPage_Gallery($galleryHtml);
        if(!$galleryPage->isValid()) {
            $gallery->lastaudit = date('Y-m-d H:i:s'); //gallery was probably deleted, so mark it as audited for now
            R::store($gallery);

            $claim = $galleryPage->getCopyrightClaim();
            if ($claim !== null) {
                Log::error(static::LOG_TAG, 'Gallery is unavailable: copyright claim by %s', $claim);
            } else {
                Log::error(static::LOG_TAG, 'Gallery was either removed or was invalid.');
            }
            return true;
        }

        $childGallery = $galleryPage->getNewestVersion();
        if($childGallery) {

            $childHtml = $this->client->gallery($childGallery->exhenid, $childGallery->hash);
            if (!$this->answered($childHtml)) {
                return false; // neither marked as replaced nor as audited
            }
            $childPage = new ExPage_Gallery($childHtml);

            if ($childPage->isValid()) {
                Log::debug(static::LOG_TAG, 'New gallery found for gallery (%d): #%d - %s', $childGallery->exhenid, $gallery->exhenid, $gallery->name);

                Model_Gallery::addGallery($childGallery->exhenid, $childGallery->hash);

                $gallery->deleted = 1;
            }
            
        }
        else {
            $newTags = $galleryPage->getTags();
            $oldTags = $gallery->exportTags();

            if(count($newTags) > 0) {
                $diff = self::tagsDiff($oldTags, $newTags);

                if(count($diff) > 0) {
                    $humanDiff = array();
                    foreach($diff as $ns => $tags) {
                        foreach($tags as $tag) {
                            $humanDiff[] = $ns.':'.$tag;
                        }
                    }

                    $humanDiff = implode(', ', $humanDiff);

                    Log::debug(static::LOG_TAG, 'Different tags found for gallery: #%d - %s (%s)', $gallery->exhenid, $gallery->name, $humanDiff);
                    $gallery->ownGalleryTag = array();
                    $gallery->addTags($newTags);

                    $cache = Cache::getInstance();
                    $cache->deleteObject('gallery', $gallery->id);
                }
            }
        }

        if ($this->updateType) {
            $gallery->type = $galleryPage->getType();
        }
        $gallery->lastaudit = date('Y-m-d H:i:s');

        if ($gallery->deleted == 1) {
            $gallery->lastaudit = null;
        }

        R::store($gallery);

        return true;
    }

    static function tagsDiff($tags1, $tags2){
        $ret = array();

        foreach ($tags1 as $ns => $tags) {
            if(!array_key_exists($ns, $tags2)) {
                $ret[$ns] = $tags;
                continue;
            }

            foreach($tags as $index => $tag) {
                if(!in_array($tag, $tags2[$ns])) {
                    if(!array_key_exists($ns, $ret) || !is_array($ret[$ns])) {
                        $ret[$ns] = array();
                    }

                    $ret[$ns][] = $tag;
                }
                else {
                    $pos = array_search($tag, $tags2[$ns]);
                    unset($tags2[$ns][$pos]);
                }
            }

            if(count($tags2[$ns]) > 0) {
                if(!array_key_exists($ns, $ret) || !is_array($ret[$ns])) {
                    $ret[$ns] = array();
                }

                $ret[$ns] = array_merge($ret[$ns], $tags2[$ns]);
            }

            unset($tags2[$ns]);
        }

        $ret = array_merge($ret, $tags2);

        return $ret;
    }

}


?>
