<?php

use Intervention\Image\Encoders\JpegEncoder;

class Model_Gallery extends Model_Abstract
{
    const SOURCE_EXHENTAI = 0;
    const SOURCE_MANUAL = 1;
    const SOURCE_NHENTAI = 2;

    const THUMB_LARGE = 1;
    const THUMB_SMALL = 2;

    const GP_PER_MB = 41.9435018158;

    public static function search($page, $pagesize, $search, $order, $randomSeed = null, $unarchived = false, $read = false, $color = false)
    {
        $query = new QueryHelper();

        if ($order === 'random') {
            $query->sql('select *, crc32(to_string(id + :seed)) as rnd from galleries');
            $query->addParams(array('seed' => $randomSeed));
        } elseif ($order === 'weight') {
            $query->sql('select *, weight() as ranked_weight from galleries');
        } else {
            $query->sql('select * from galleries');
        }

        $query->sql('where deleted < 3');

        if (!$unarchived) {
            $query->sql('and archived = 1');
        }
        
        if ($read !== false) {
            // "read" is a string attribute in the search index; Manticore rejects comparing it with an integer
            $query->sql('and read = :read')
                ->addParams(array('read' => array((string)$read, PDO::PARAM_STR)));
        }
        
        if ($color !== false) {
            $query->sql('and color = :color')
                ->addParams(array('color' => $color));
        }

        if ($search) {
            $search = SphinxQL::halfEscapeMatch($search);
            
            $query->sql('and match(:match)')
                ->addParams(array('match' =>  $search));
        }

        if ($order === 'added') {
            $query->sql('order by added desc');
        } elseif ($order === 'random') {
            $query->sql('order by rnd asc');
        } elseif ($order === 'weight') {
            $query->sql('order by ranked_weight desc, posted desc');
        } else {
            $query->sql('order by posted desc');
        }

        if ($page >= 0 && $pagesize) {
            $query->sql('limit :offset, :limit')
                ->addParams(array(
                    'offset' => (int)($page * $pagesize),
                    'limit' => (int)$pagesize
                ));
        }

        if ($order === 'weight') {
            $query->sql('option ranker = wordcount,');
        } else {
            $query->sql('option ranker = none,');
        }

        $maxMatches = 500000;
        $query->sql('max_matches = '.$maxMatches);

        $result = SphinxQL::query($query->getSql(), $query->getParams());
        $meta = SphinxQL::getMeta();

        // Sphinx 2.2 reported "total" as all retrievable matches (total_found capped by max_matches);
        // Manticore reports only the rows of the current page. The API's "end" flag and the result
        // counter rely on the Sphinx meaning, so restore it.
        if (isset($meta->total_found)) {
            $meta->total = (string)min((int)$meta->total_found, $maxMatches);
        }

        return array('result' => $result, 'meta' => $meta);
    }

    // this really needs redoing..
    public function getArchiveFilepath()
    {
        if ($this->source == self::SOURCE_EXHENTAI) {
            return sprintf('%s/galleries/%d.zip', Config::get()->archiveDir, $this->exhenid);
        } else {
            return sprintf('%s/galleries/%d-%d.zip', Config::get()->archiveDir, $this->source, $this->id);
        }
    }

    /** page list of the last opened archive (Thumbnails reads every page of a gallery in one process) */
    private static $entryCache = array();

    /**
     * File entries of the gallery archive in page order (natural, case-insensitive sort by name).
     *
     * Archives are read with ZipArchive (libzip). The previously used Phar could not read ZIP64 archives,
     * which every zip over 4 GB is ("phar error: corrupted central directory entry, no magic signature").
     *
     * @return string[] entry names
     */
    public function getImageEntries()
    {
        $zipPath = $this->getArchiveFilepath();
        if (!file_exists($zipPath)) {
            return array();
        }

        clearstatcache(true, $zipPath);
        $key = $zipPath.'|'.filemtime($zipPath).'|'.filesize($zipPath);

        if (!isset(self::$entryCache[$key])) {
            $names = array();

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::RDONLY) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $name = $zip->getNameIndex($i);
                    if ($name !== false && substr($name, -1) !== '/') { // skip directory entries
                        $names[] = $name;
                    }
                }
                $zip->close();
            }

            natcasesort($names);
            self::$entryCache = array($key => array_values($names));
        }

        return self::$entryCache[$key];
    }

    /**
     * @return array|false page info: archive (zip path), name (entry name), size, mtime
     */
    public function getImageEntry($index)
    {
        $entries = $this->getImageEntries();
        if (!is_numeric($index) || !array_key_exists((int)$index, $entries)) {
            return false;
        }

        $zipPath = $this->getArchiveFilepath();
        $name = $entries[(int)$index];

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
            return false;
        }
        $stat = $zip->statName($name);
        $zip->close();

        if ($stat === false) {
            return false;
        }

        return array('archive' => $zipPath, 'name' => $name, 'size' => $stat['size'], 'mtime' => $stat['mtime']);
    }

    /**
     * @return string|false the page's file contents
     */
    public function getImageData($index)
    {
        $entry = $this->getImageEntry($index);
        if (!$entry) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($entry['archive'], ZipArchive::RDONLY) !== true) {
            return false;
        }
        $data = $zip->getFromName($entry['name']);
        $zip->close();

        return $data;
    }

    /**
     * Sends the page's file contents to the output, streamed (large originals are not loaded into memory).
     *
     * @return bool
     */
    public function outputImage($index)
    {
        $entry = $this->getImageEntry($index);
        if (!$entry) {
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($entry['archive'], ZipArchive::RDONLY) !== true) {
            return false;
        }

        // the stream reads through the open archive, so it has to stay open until the stream is done
        $stream = $zip->getStream($entry['name']);
        if ($stream) {
            fpassthru($stream);
            fclose($stream);
        }
        $zip->close();

        return (bool)$stream;
    }

    public function getImageBean($index)
    {
        return R::findOne('galleryimage', 'gallery_id = ? and galleryimage.index = ?', array($this->id, $index));
    }

    public function getThumbnail($index, $type, $create = true)
    {
        if ($this->archived) {
            $link = $this->unbox()->withCondition('gallery_thumb.index = ? and gallery_thumb.type = ?', array($index, $type))->via('gallery_thumb')->ownGalleryThumb;

            if (count($link) > 0) {
                $link = array_pop($link);

                return $link->image;
            } elseif ($create) {
                $imageData = $this->getImageData($index);

                if ($imageData !== false && $imageData !== '') {
                    $resizedFilename = sprintf('resized_gallery_%d_%d.jpg', $this->id, $index);

                    $layer = ImageTool::loadData($imageData);
                    unset($imageData);

                    if ($type == self::THUMB_LARGE) {
                        $layer->scale(width: 350);
                    } elseif ($type == self::THUMB_SMALL) {
                        $layer->scale(width: 140);
                    } else {
                        return false;
                    }

                    $tempDir = Config::get()->tempDir;
                    if (!is_dir($tempDir)) {
                        mkdir($tempDir, 0777, true);
                    }

                    $outFile = $tempDir.DS.$resizedFilename;
                    $layer->encode(new JpegEncoder(quality: 95))->save($outFile);
                    $image = Model_Image::importFromFile($outFile);

                    unlink($outFile);

                    $link = $image->unbox()->link('gallery_thumb', array('index' => $index, 'type' => $type))->gallery = $this->unbox();
                    R::store($image);

                    return $image;
                }
            }
        }

        return false;
    }

    public function dispense()
    {
        $this->added = date('Y-m-d H:i:s');
    }

    public function update()
    {
        $this->updated = date('Y-m-d H:i:s');
    }

    public function getRoundedId()
    {
        return round(($this->exhenid - 500), -4);
    }

    public static function getStats()
    {
        $sql =
            'select
				count(id) as count_total,
				sum(filesize) as filesize,
				round(sum(filesize) / pow(1024, 3), 2) as filesize_gb,
				(select count(id) from gallery where archived = 1) as count_archived,
				(select count(id) from gallery where deleted >= 1) as count_deleted,
				round((sum(filesize) / (select count(id) from gallery where archived = 1)) / pow(1024, 2), 2) as filesize_average_mb,
				sum(round(round(filesize / pow(1024, 2), 2) * 41.9435018158)) as gp_total,
				round(sum(round(filesize / pow(1024, 2), 2) * 41.9435018158) / (select count(id) from gallery where archived = 1)) as gp_average
			from gallery
			limit 1';

        $stats = R::getRow($sql);

        return $stats;
    }

    public function hasTag($ns, $tag)
    {
        $tagBean = R::findOne('tag', 'name = ?', array($tag));
        $nsBean = R::findOne('tagnamespace', 'name = ?', array($ns));

        if (!$tagBean || !$nsBean) {
            return false;
        }

        $result = $this->unbox()->withCondition('tag_id = ? and namespace_id = ?', array($tagBean->id, $nsBean->id))->ownGalleryTag;
        return (count($result) > 0);
    }

    public function addTags($tagList)
    {
        foreach ($tagList as $tagNamespace => $tags) {
            $ns = R::findOne('tagnamespace', 'name = ?', array($tagNamespace));
            if (!$ns) {
                $ns = R::dispense('tagnamespace');
                $ns->name = $tagNamespace;
                R::store($ns);
            }

            foreach ($tags as $tagName) {
                $tag = R::findOne('tag', 'name = ?', array($tagName));
                if (!$tag) {
                    $tag = R::dispense('tag');
                    $tag->name = $tagName;
                    R::store($tag);
                }

                $this->unbox()->link('gallery_tag', array('namespace' => $ns))->tag = $tag;
            }
        }
    }

    public function addProperties($props)
    {
        foreach ($props as $name => $value) {
            $galleryAttr = R::dispense('galleryproperty');
            $galleryAttr->name = $name;
            $galleryAttr->value = $value;
            $galleryAttr->gallery = $this->unbox();
            R::store($galleryAttr);

            if ($name === 'Posted') {
                $this->unbox()->posted = $value;
            }
        }
    }

    public static function addGallery($gid, $hash)
    {
        $gallery = R::findOne('gallery', 'exhenid = ?', array($gid));
        if (!$gallery) {
            $gallery = R::dispense('gallery');
            $gallery->exhenid = $gid;
            $gallery->hash = $hash;
            R::store($gallery);
        } elseif (!$gallery->download) {
            $gallery->download = true;
            $gallery->added = date('Y-m-d H:i:s');
            R::store($gallery);
        }

        return $gallery;
    }

    public function exportTags()
    {
        $tagLinks = $this->ownGalleryTag;

        $export = array();
        foreach ($tagLinks as $link) {
            // gallery_tag.namespace_id references the tagnamespace table
            $namespace = $link->fetchAs('tagnamespace')->namespace;

            if (!array_key_exists($namespace->name, $export)) {
                $export[$namespace->name] = array();
            }

            $export[$namespace->name][] = $link->tag->name;
        }

        return $export;
    }
    
    public function exportSource()
    {
        return $this->source;
    }
    
    public function exportFeed()
    {
        return $this->feed_id;
    }
}
