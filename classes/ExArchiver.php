<?php

class ExArchiver
{
    const LOG_TAG = 'ExArchiver';

    protected $client;
    protected $archiveDir;
    protected $config;
    protected $cache;

    /** set when ExHentai answers "Insufficient Credits.": no further downloads in this run */
    protected $outOfCredits = false;

    public function __construct()
    {
        $this->client = new ExClient();

        $this->config = Config::get();
        $this->cache = Cache::getInstance();

        $this->archiveDir = $this->config->archiveDir.'/galleries';
        if (!is_dir($this->archiveDir)) {
            mkdir($this->archiveDir, 0777, true);
        }

        $pagesDir = $this->config->archiveDir.'/pages';
        if (!is_dir($pagesDir)) {
            mkdir($pagesDir, 0777, true);
        }
    }

    public function start($forced_feed = false)
    {
        $archivedCount = 0;

        try {
            //archive unarchived galleries
            $unarchived = R::find('gallery', '((archived = 0 and download = 1) or hasmeta = 0) and deleted = 0 and source = 0 ORDER BY exhenid DESC');
            foreach ($unarchived as $gallery) {
                if ($this->outOfCredits) {
                    break;
                }

                $this->archiveGallery($gallery);

                if ($gallery->archived) {
                    $archivedCount++;
                }
            }

            //run through feeds and add new galleries
            if ($this->outOfCredits) {
                $feeds = array(); // stop this run; the galleries are tried again next time
            } elseif ($forced_feed == false) {
                $feeds = R::find('feed', 'disabled = 0');
            } else {
                $feeds = R::find('feed', 'disabled = 0 and id = :id', [':id' => $forced_feed]);
            }

            foreach ($feeds as $feed) {
                if ($this->outOfCredits) {
                    break;
                }
                $page = 0;
                $next = null;      // ExHentai pages search results with a cursor (?next=<gid>)
                $seen = array();

                while (true) {
                    Log::debug(self::LOG_TAG, 'Crawling feed "%s", page %d', $feed->term, $page);

                    $params = array();

                    if ($feed->expunged) {
                        $params['f_sh'] = 'on';
                    }

                    $indexHtml = $this->client->index($feed->term, $next, $params);
                    $indexPage = new ExPage_Index($indexHtml);

                    $isLastPage = $indexPage->isLastPage();
                    $hasGallery = false;

                    $galleries = $indexPage->getGalleries();
                    if (count($galleries) == 0) {
                        break;
                    } else {
                        foreach ($galleries as $exGallery) {
                            $gallery = R::findOne('gallery', 'exhenid = ?', array($exGallery->exhenid));
                            if (!$gallery) {
                                $gallery = R::dispense('gallery');
                                $gallery->exhenid = $exGallery->exhenid;
                                $gallery->hash = $exGallery->hash;
                                $gallery->name = $exGallery->name;
                                $gallery->archived = false;
                                $gallery->feed = $feed;
                                $gallery->download = $feed->download;
                                R::store($gallery);
                            } else {
                                if ($gallery->feed_id == $feed->id && $feed->archived) { // compare ids: two loaded beans are never ==
                                    $hasGallery = true;
                                }

                                if ($feed->download && $gallery->deleted == 0) {
                                    $gallery->download = true;
                                    R::store($gallery);
                                }
                            }

                            if ($this->outOfCredits) {
                                break;
                            }

                            if ((!$gallery->archived && $gallery->download) || !$gallery->hasmeta) {
                                $this->archiveGallery($gallery);

                                if ($gallery->archived) {
                                    $archivedCount++;
                                }
                            }
                        }
                    }

                    if ($isLastPage) {
                        $feed->archived = true;
                        R::store($feed);
                    }

                    if ($isLastPage || $hasGallery || $this->outOfCredits) {
                        break;
                    }

                    $next = $indexPage->getNextCursor();
                    if (isset($seen[$next])) {
                        Log::error(self::LOG_TAG, 'Feed "%s": page %s was already crawled, stopping', $feed->term, $next);
                        break;
                    }
                    $seen[$next] = true;
                    $page++;
                }
            }

        } catch (ExClient_BannedException $e) {
            // retrying would only extend the ban: stop this run, the next one continues
            Log::error(self::LOG_TAG, '%s: stopped this run, the remaining galleries are tried again next time', $e->getMessage());
        }

        if ($this->outOfCredits) {
            Log::error(self::LOG_TAG, 'Insufficient Credits: stopped this run, the remaining galleries are tried again next time');
        }

        Log::debug(self::LOG_TAG, 'Archive complete. Archived %d galleries.', $archivedCount);
    }

    protected function archiveGallery($gallery)
    {
        Log::debug(self::LOG_TAG, 'Archiving gallery: #%d', $gallery->exhenid);

        $this->cache->deleteObject('gallery', $gallery->id);

        $galleryHtml = $this->client->gallery($gallery->exhenid, $gallery->hash);
        if (!$galleryHtml || $this->client->lastStatus >= 500) { // no answer, or a server error page
            Log::error(self::LOG_TAG, 'Failed to retrieve page from server: %s',
                $this->client->lastError ?: 'HTTP '.$this->client->lastStatus);
            return;
        }

        $galleryPage = new ExPage_Gallery($galleryHtml);

        // error pages (copyright claim, removed gallery) must not replace the stored metadata
        if (!$galleryPage->isValid()) {
            $claim = $galleryPage->getCopyrightClaim();
            if ($claim !== null) {
                Log::error(self::LOG_TAG, 'Gallery #%d unavailable: copyright claim by %s', $gallery->exhenid, $claim);
                if (!$gallery->archived) {
                    // can't be downloaded anymore: unqueue it (the userscript can queue it again)
                    $gallery->download = false;
                    $gallery->hasmeta = true;
                    R::store($gallery);
                }
            } else {
                Log::error(self::LOG_TAG, 'Gallery #%d: ExHentai returned no gallery page (removed or invalid)', $gallery->exhenid);
            }
            return;
        }

        // save html
        $pagesFile = sprintf('%s/pages/%d.html', $this->config->archiveDir, $gallery->exhenid);
        file_put_contents($pagesFile, $galleryHtml);

        //name, jap name, type
        $gallery->name = $galleryPage->getName();
        $gallery->origtitle = $galleryPage->getOriginalName();
        $gallery->type = $galleryPage->getType();

        //tags
        $gallery->ownGalleryTag = array(); //remove tags
        $tags = $galleryPage->getTags();
        $gallery->addTags($tags);

        //properties
        $gallery->ownGalleryproperty = array(); //remove properties
        $props = $galleryPage->getProperties();
        $gallery->addProperties($props);

        $gallery->hasmeta = true;

        if ($gallery->download) {
            //delete if gallery zip exists (it shouldn't)
            $targetFile = $gallery->getArchiveFilepath();
            if (file_exists($targetFile)) {
                unlink($targetFile);
            }

            Log::debug(self::LOG_TAG, 'Downloading gallery archive');

            //download archive
            $archiverUrl = $galleryPage->getArchiverUrl();
            if (!$archiverUrl) {
                Log::error(self::LOG_TAG, 'Failed to find archiver link for gallery: %s (#%d)', $gallery->name, $gallery->exhenid);
                R::store($gallery);
                return;
            }

            $buttonPress = $this->client->buttonPress($archiverUrl);

            if (strpos($buttonPress, 'Insufficient Credits.') !== false) {
                Log::error(self::LOG_TAG, 'Insufficient Credits for gallery: %s (#%d)', $gallery->name, $gallery->exhenid);
                $this->outOfCredits = true;
                R::store($gallery); // keep the metadata
                return;
            }

            $archiverPage = new ExPage_Archiver($buttonPress);

            $continueUrl = $archiverPage->getContinueUrl();

            if ($continueUrl) {
                if (preg_match("/\?.*/", $continueUrl)) {
                    $continueUrl = preg_replace('/\?.*/', '', $continueUrl);
                }
                $archiveDownloadUrl = $continueUrl.'?start=1';
                $error = $this->download($archiveDownloadUrl, $targetFile);

                if ($error === null) {
                    $archive = new ZipArchive();
                    $ret = $archive->open($targetFile);
                    if ($ret === true && $archive->status == ZipArchive::ER_OK) {
                        $gallery->numfiles = Model_Gallery::countPages($archive);
                        $archive->close();

                        $gallery->filesize = filesize($targetFile);
                        $gallery->archived = true;
                    } else {
                        Log::error(self::LOG_TAG, 'Downloaded file is not an archive for gallery: %s (#%d)', $gallery->name, $gallery->exhenid);
                    }
                } else {
                    Log::error(self::LOG_TAG, 'Failed to download archive for gallery: %s (#%d): %s', $gallery->name, $gallery->exhenid, $error);
                }
            } else {
                Log::error(self::LOG_TAG, 'Failed to find archive link for gallery: %s (#%d) - low GP?', $gallery->name, $gallery->exhenid);
            }
        }

        R::store($gallery);
    }

    /**
     * Downloads $url to $targetFile (one attempt; a failed download is tried again by a later run).
     * Gives up when no data arrives for "exhentai.downloadTimeout" seconds (default 120).
     *
     * @return string|null null on success, otherwise what went wrong (HTTP status, SSL/connection error, timeout)
     */
    protected function download($url, $targetFile)
    {
        $timeout = (int)($this->config->exhentai->downloadTimeout ?? 120);
        $context = stream_context_create(array(
            'http' => array('timeout' => $timeout, 'follow_location' => 1, 'ignore_errors' => false),
        ));

        error_clear_last();
        $in = @fopen($url, 'rb', false, $context);
        if (!$in) {
            $error = error_get_last();
            $message = $error ? $error['message'] : 'unknown error';
            // "fopen(https://...): Failed to open stream: HTTP request failed! HTTP/1.1 404 Not Found"
            return preg_replace('~^fopen\([^)]*\): ~', '', $message);
        }

        $out = fopen($targetFile, 'wb');
        $bytes = stream_copy_to_stream($in, $out);
        $meta = stream_get_meta_data($in);
        fclose($out);
        fclose($in);

        if (!empty($meta['timed_out'])) {
            return sprintf('timed out after %d s without data (%d bytes received)', $timeout, (int)$bytes);
        }
        if ($bytes === false) {
            return 'reading the download failed';
        }

        return null;
    }
}
