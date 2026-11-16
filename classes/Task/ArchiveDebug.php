<?php

/**
 * Shows and saves what the app gets from ExHentai for one gallery, step by step like the Archive task
 * (ExArchiver::archiveGallery), to find out why a gallery isn't archived. Never changes the database.
 *
 *   TaskRunner.php ArchiveDebug <gid> [token] [download] [keep]
 *
 * The token is taken from the database when it's not given. Without "download" it stops at the archiver
 * form (free); "download" also presses the download button (spends GP/credits like a real run) and downloads
 * the archive to <archiveDir>/debug/<gid>.zip, which is deleted afterwards unless "keep" is given.
 *
 * Every response is saved with its headers in <tempDir>/archive-debug/<gid>-<date>-<time>/, together with
 * summary.txt (what was printed). Cookie values are never printed; the saved pages contain the API key and
 * uid like any page you're logged into.
 */
class Task_ArchiveDebug extends Task_Abstract {

	const LOG_TAG = 'Task_ArchiveDebug';

	/** text in a page => what it means */
	const MARKERS = array(
		'Content Warning' => 'Content Warning page: the gallery is flagged as offensive and ExHentai asks for confirmation (?nw=session)',
		'Key missing, or incorrect key provided' => 'wrong token (hash) for this gallery',
		'Gallery Not Available' => 'gallery not available (removed or copyright claim)',
		'This gallery has been removed' => 'gallery removed',
		'opening pages too fast' => '"You are opening pages too fast" warning',
		'temporarily banned' => 'IP ban page',
		'Insufficient Credits' => 'not enough GP/credits',
		'act=Login' => 'login form: the cookies are not accepted',
	);

	protected $client;
	protected $dir;
	protected $lines = array();

	public function run($options = array()) {
		$gid = isset($options[0]) ? (int)$options[0] : 0;
		$args = array_slice($options, 1);
		$download = in_array('download', $args, true);
		$keep = in_array('keep', $args, true);
		$args = array_values(array_diff($args, array('download', 'keep')));
		$token = isset($args[0]) ? $args[0] : null;

		if ($gid <= 0) {
			Log::error(self::LOG_TAG, 'Usage: TaskRunner.php ArchiveDebug <gid> [token] [download] [keep]');
			return;
		}

		if ($token === null) {
			$gallery = R::findOne('gallery', 'exhenid = ?', array($gid));
			if (!$gallery) {
				Log::error(self::LOG_TAG, 'Gallery #%d is not in the database, give the token: ArchiveDebug %d <token>', $gid, $gid);
				return;
			}
			$token = $gallery->hash;
			$this->out('Database: id %d, archived %d, download %d, hasmeta %d, deleted %d, name "%s"',
				$gallery->id, $gallery->archived, $gallery->download, $gallery->hasmeta, $gallery->deleted, $gallery->name);
		}

		$this->dir = sprintf('%s/archive-debug/%d-%s', Config::get()->tempDir, $gid, date('Ymd-His'));
		mkdir($this->dir, 0777, true);
		$this->client = new ExClient();

		try {
			$this->debug($gid, $token, $download, $keep);
		} catch (ExClient_BannedException $e) {
			$this->out('STOP: %s', $e->getMessage());
		}

		file_put_contents($this->dir.'/summary.txt', implode("\n", $this->lines)."\n");
		Log::debug(self::LOG_TAG, 'Saved in %s', $this->dir);
	}

	protected function debug($gid, $token, $download, $keep) {
		$this->out('User agent: %s', Config::get()->exhentai->userAgent ?? ExClient::USER_AGENT); // as ExClient::userAgent()
		$cookies = array();
		foreach (Config::get()->cookie as $name => $value) {
			$cookies[] = $name.($value === '' || $value === null ? ' (EMPTY)' : ' (set)');
		}
		$this->out('Cookies sent: %s', implode(', ', $cookies));

		// 1. gallery page
		$html = $this->client->gallery($gid, $token);
		$this->response('01-gallery', 'Gallery page', $html);

		$page = new ExPage_Gallery((string)$html);
		if (!is_string($html) || $html === '' || !$page->isValid()) {
			$this->out('Gallery page not valid (no h1#gn, #taglist, #gdd): the Archive task stops here');
			$claim = $page->getCopyrightClaim();
			if ($claim !== null) {
				$this->out('Copyright claim by %s', $claim);
			}
			return;
		}

		// 2. what the Archive task reads from it
		$tags = 0;
		foreach ($page->getTags() as $list) {
			$tags += count($list);
		}
		$archiverUrl = $page->getArchiverUrl();
		$this->out('Parsed: "%s", type %s, %d tags, newer version: %s', $page->getName(), $page->getType(), $tags,
			$page->getNewestVersion() ? 'yes' : 'no');
		$this->out('Archiver URL: %s', $archiverUrl ?: 'NOT FOUND (the Archive task logs "Failed to find archiver link")');
		if (!$archiverUrl) {
			return;
		}

		// 3. archiver form (a GET, free)
		$form = $this->client->exec($archiverUrl);
		$this->response('02-archiver-form', 'Archiver form', $form);
		$formText = (new ExPage_Archiver((string)$form))->getVisibleText(1000);
		$this->out('dlcheck button: %s', strpos((string)$form, 'dlcheck') !== false ? 'yes' : 'NO');
		if (preg_match('/Download Cost: (.+?) Download/u', $formText, $m)) {
			$this->out('Original archive cost: %s', $m[1]);
		}
		if (preg_match('/Estimated Size: (\S+ \S+)/u', $formText, $m)) {
			$this->out('Estimated size: %s', $m[1]);
		}

		if (!$download) {
			$this->out('Stopped before the download button (add "download" to press it, spends GP like a real run)');
			return;
		}

		// 4. download button (POST)
		$result = $this->client->buttonPress($archiverUrl);
		$this->response('03-archiver-result', 'Archiver result (POST)', $result);
		$continueUrl = (new ExPage_Archiver((string)$result))->getContinueUrl();
		$this->out('Continue URL: %s', $continueUrl ?: 'NOT FOUND (the Archive task logs "Failed to find archive link")');
		if (!$continueUrl) {
			return;
		}

		// 5. the archive, like ExArchiver::archiveGallery
		$url = preg_replace('/\?.*/', '', $continueUrl).'?start=1';
		$target = Config::get()->archiveDir.'/debug/'.$gid.'.zip';
		if (!is_dir(dirname($target))) {
			mkdir(dirname($target), 0777, true);
		}
		$this->out('Downloading %s', $url);
		$start = microtime(true);
		$archiver = new class extends ExArchiver { // the Archive task's own download, made callable here
			public function download($url, $targetFile) { return parent::download($url, $targetFile); }
		};
		$error = $archiver->download($url, $target);
		$bytes = is_file($target) ? filesize($target) : 0;
		$this->out('Download: %s, %d bytes in %.1f s', $error === null ? 'OK' : 'FAILED: '.$error, $bytes, microtime(true) - $start);

		if ($bytes > 0) {
			$head = (string)file_get_contents($target, false, null, 0, 300);
			$zip = new ZipArchive();
			if ($zip->open($target) === true) {
				$this->out('Zip: OK, %d pages', Model_Gallery::countPages($zip));
				$zip->close();
			} else {
				$this->out('Zip: NOT A ZIP, starts with: %s', preg_replace('/[^\x20-\x7e]/', '.', $head));
			}
		}

		if ($keep) {
			$this->out('Kept %s', $target);
		} elseif (is_file($target)) {
			unlink($target);
		}
	}

	/** prints a response's details and diagnosis and saves it as <name>.html / <name>.headers.txt */
	protected function response($name, $label, $body) {
		$info = $this->client->lastInfo;
		$this->out('%s: HTTP %d%s, %s, %d bytes, %.1f s, %d redirects, URL %s', $label, $this->client->lastStatus,
			$this->client->lastError ? ' (curl: '.$this->client->lastError.')' : '',
			$info['content_type'] ?? 'no content type', is_string($body) ? strlen($body) : 0,
			$info['total_time'] ?? 0, $info['redirect_count'] ?? 0, $info['url'] ?? '');

		file_put_contents($this->dir.'/'.$name.'.html', (string)$body);
		file_put_contents($this->dir.'/'.$name.'.headers.txt', $this->client->lastHeaders);

		if (!is_string($body) || $body === '') {
			$this->out('  Empty answer%s', $this->client->lastError ? '' : ': usually "Sad Panda", the cookies are not accepted (igneous, ipb_member_id, ipb_pass_hash)');
			return;
		}
		if (stripos($info['content_type'] ?? '', 'image/') === 0) {
			$this->out('  An image instead of a page: "Sad Panda", the cookies are not accepted');
			return;
		}

		$page = new ExPage_Gallery($body);
		$this->out('  Title: "%s"', $page->getTitle());
		$this->out('  Text: "%s"', $page->getVisibleText(300));
		foreach (self::MARKERS as $needle => $meaning) {
			if (stripos($body, $needle) !== false) {
				$this->out('  Found "%s": %s', $needle, $meaning);
			}
		}
	}

	protected function out($message) {
		$args = func_get_args();
		$text = count($args) > 1 ? vsprintf($message, array_slice($args, 1)) : $message;
		$this->lines[] = $text;
		Log::debug(self::LOG_TAG, '%s', $text);
	}
}
