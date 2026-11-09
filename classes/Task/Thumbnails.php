<?php

class Task_Thumbnails extends Task_Abstract {

	const LOG_TAG = 'Task_Thumbnails';

	public function run($options = array()) {
		$galleries = R::find('gallery', 'numfiles != (SELECT count(*) FROM gallery_thumb where gallery_thumb.type = 2 and gallery_id = gallery.id)');

		$count = 0;

		foreach($galleries as $gallery) {
			$success = true;

			// galleries stored before directories were left out of numfiles counted them as pages
			$pages = count($gallery->getImageEntries());
			if ($pages > 0 && $pages != $gallery->numfiles) {
				Log::debug(self::LOG_TAG, 'Gallery #%d has %d pages, not %d', $gallery->id, $pages, $gallery->numfiles);
				$gallery->numfiles = $pages;
				R::store($gallery);
				Cache::getInstance()->deleteObject('gallery', $gallery->id);
			}

        	for($i = 0; $i < $gallery->numfiles; $i++) {
                try {
                	$gallery->getThumbnail($i, 2, true);
				}
                catch(Exception $e) {
					$success = false;
                }
        	}

			if($success) {
				$count++;
			}
		}

		Log::debug(self::LOG_TAG, 'Processed thumbnails for %d galleries', $count);

		$this->generateCovers();
	}

	/**
	 * Creates the large cover thumbnail (first page) that the gallery list shows, for every archived gallery
	 * that has none yet. Without it the list falls back to api.php?action=gallerythumb, which makes PHP create
	 * the cover while the list is loading. Processed in batches by id, so large archives don't load into memory
	 * at once and galleries that fail (e.g. missing zip) are not retried in an endless loop.
	 */
	protected function generateCovers() {
		$cache = Cache::getInstance();
		$created = 0;
		$failed = 0;
		$lastId = 0;

		while (true) {
			$galleries = R::find('gallery',
				'archived = 1 and deleted = 0 and id > ? and not exists ('.
				'select 1 from gallery_thumb where gallery_thumb.gallery_id = gallery.id '.
				'and gallery_thumb.type = ? and gallery_thumb."index" = 0) order by id limit 200',
				array($lastId, Model_Gallery::THUMB_LARGE));

			if (count($galleries) === 0) {
				break;
			}

			foreach ($galleries as $gallery) {
				$lastId = $gallery->id;

				try {
					$thumb = $gallery->getThumbnail(0, Model_Gallery::THUMB_LARGE, true);
				}
				catch (Exception $e) {
					$thumb = false;
				}

				if ($thumb) {
					$created++;
					// cached list entries don't know the new cover yet (same as api.php?action=gallerythumb)
					$cache->deleteObject('gallery', $gallery->id);
				} else {
					$failed++;
					Log::error(self::LOG_TAG, 'Failed to create cover for gallery #%d (%s)', $gallery->id, $gallery->name);
				}
			}
		}

		Log::debug(self::LOG_TAG, 'Created %d covers (%d failed)', $created, $failed);
	}

}


?>
