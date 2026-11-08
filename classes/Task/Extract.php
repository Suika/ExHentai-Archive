<?php

/**
 * Converts gallery archives (zip) to uncompressed tar files:
 *   <archiveDir>/archives/<rounded id>/<exhenid>.tar          ExHentai galleries
 *   <archiveDir>/archives/<rounded id>/<source>-<id>.tar      other galleries (same naming as their zip)
 * The rounded id is the gallery's ExHentai id (or for other galleries its id) rounded to 10000s.
 * Existing tar files are replaced. The zip files are kept.
 *
 * Usage: TaskRunner.php Extract          all archived, not deleted galleries
 *        TaskRunner.php Extract <id>     one gallery
 *
 * Pages are read with ZipArchive, which also handles ZIP64 archives (over 4 GB) that Phar can't open.
 */
class Task_Extract extends Task_Abstract {

	const LOG_TAG = 'Task_Extract';

	public function run($options = array()) {
		$converted = 0;
		$failed = 0;

		if (isset($options[0])) {
			$gallery = R::load('gallery', (int)$options[0]);
			if (!$gallery->id) {
				Log::error(self::LOG_TAG, 'Gallery not found: %s', $options[0]);
				return;
			}
			$this->extract($gallery) ? $converted++ : $failed++;
		} else {
			$lastId = 0;
			while (true) {
				$galleries = R::find('gallery', 'archived = 1 and deleted = 0 and id > ? order by id limit 100', array($lastId));
				if (count($galleries) == 0) {
					break;
				}

				foreach ($galleries as $gallery) {
					$lastId = $gallery->id;
					$this->extract($gallery) ? $converted++ : $failed++;
				}
			}
		}

		Log::debug(self::LOG_TAG, 'Converted %d galleries (%d failed)', $converted, $failed);
	}

	public static function tarPath($gallery) {
		$baseId = $gallery->source == Model_Gallery::SOURCE_EXHENTAI ? $gallery->exhenid : $gallery->id;
		$folder = sprintf('%s/archives/%d', Config::get()->archiveDir, round($baseId - 500, -4));

		if ($gallery->source == Model_Gallery::SOURCE_EXHENTAI) {
			return sprintf('%s/%d.tar', $folder, $gallery->exhenid);
		}

		return sprintf('%s/%d-%d.tar', $folder, $gallery->source, $gallery->id);
	}

	protected function extract($gallery) {
		$zipPath = $gallery->getArchiveFilepath();
		if (!file_exists($zipPath)) {
			Log::error(self::LOG_TAG, 'Zip file doesn\'t exist: %s', $zipPath);
			return false;
		}

		$zip = new ZipArchive();
		if ($zip->open($zipPath, ZipArchive::RDONLY) !== true) {
			Log::error(self::LOG_TAG, 'Can\'t open zip file: %s', $zipPath);
			return false;
		}

		$tarPath = self::tarPath($gallery);
		if (!is_dir(dirname($tarPath))) {
			mkdir(dirname($tarPath), 0777, true);
		}

		// written next to the target first, so an interrupted run never leaves a partial tar behind
		$tempPath = substr($tarPath, 0, -4).'.tmp.tar';
		if (file_exists($tempPath)) {
			unlink($tempPath);
		}

		try {
			$tar = new PharData($tempPath, 0, null, Phar::TAR);
			$tar->startBuffering();

			for ($i = 0; $i < $zip->numFiles; $i++) {
				$name = $zip->getNameIndex($i);
				if ($name === false || substr($name, -1) === '/') {
					continue; // directories are created by their files
				}

				$data = $zip->getFromIndex($i);
				if ($data === false) {
					throw new Exception('Can\'t read '.$name);
				}
				$tar->addFromString($name, $data);
			}

			$tar->stopBuffering();
			unset($tar);
		} catch (Exception $e) {
			$zip->close();
			if (file_exists($tempPath)) {
				unlink($tempPath);
			}
			Log::error(self::LOG_TAG, 'Converting gallery #%d failed: %s', $gallery->id, $e->getMessage());
			return false;
		}

		$zip->close();
		rename($tempPath, $tarPath);

		Log::debug(self::LOG_TAG, 'Gallery #%d: %s', $gallery->id, $tarPath);

		return true;
	}
}

?>
