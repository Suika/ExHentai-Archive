<?php

namespace Tests\Support;

/**
 * Controls the fake ExHentai server (tests/fake-exhentai/router.php) through its state file.
 */
final class FakeExhentai
{
    private array $state = array('galleries' => array(), 'index' => array(), 'archiver' => 'normal');

    public static function stateDir(): string
    {
        return getenv('FAKE_EXHENTAI_STATE') ?: '/var/tmp/fake-exhentai';
    }

    public function reset(): self
    {
        $dir = self::stateDir();
        foreach (glob($dir.'/archives/*') ?: array() as $file) {
            unlink($file);
        }
        if (!is_dir($dir.'/archives')) {
            mkdir($dir.'/archives', 0777, true);
        }
        file_put_contents($dir.'/requests.log', '');

        $this->state = array('galleries' => array(), 'index' => array(), 'archiver' => 'normal');
        return $this->save();
    }

    /**
     * Serves a saved gallery page at /g/{gid}/... ($options: replace => [from => to], status => code).
     */
    public function gallery(int $gid, string $fixture, array $options = array()): self
    {
        $this->state['galleries'][(string)$gid] = array('fixture' => $fixture) + $options;
        return $this->save();
    }

    /** Serves the given HTML at /g/{gid}/... */
    public function galleryHtml(int $gid, string $html, int $status = 200): self
    {
        $this->state['galleries'][(string)$gid] = array('html' => $html, 'status' => $status);
        return $this->save();
    }

    public function removeGallery(int $gid): self
    {
        unset($this->state['galleries'][(string)$gid]);
        return $this->save();
    }

    /** @param array<string,string> $fixtures search page fixture per "next" cursor ("" = first page) */
    public function index(array $fixtures): self
    {
        $this->state['index'] = $fixtures;
        return $this->save();
    }

    /** Serves the given HTML as the only search result page */
    public function indexHtml(string $html): self
    {
        $this->state['indexHtml'] = $html;
        return $this->save();
    }

    /**
     * "normal" or "credits" (POST returns "Insufficient Credits."), the latter for all galleries or only
     * the given ones
     */
    public function archiver(string $mode, array $gids = array()): self
    {
        $this->state['archiver'] = $mode;
        $this->state['creditsFor'] = array_map('intval', $gids);
        return $this->save();
    }

    /** The archive that /archive/{gid}.zip serves */
    public function archive(int $gid, array $entries): string
    {
        return ZipFactory::create(self::stateDir().'/archives/'.$gid.'.zip', $entries);
    }

    /** "normal", "missing" (HTTP 404) or "stall" (a few bytes, then nothing) for /archive/{gid}.zip */
    public function zipMode(int $gid, string $mode): self
    {
        $this->state['zip'][(string)$gid] = $mode;
        return $this->save();
    }

    /** Serves arbitrary bytes as /archive/{gid}.zip */
    public function archiveRaw(int $gid, string $bytes): void
    {
        file_put_contents(self::stateDir().'/archives/'.$gid.'.zip', $bytes);
    }

    /** @return array[] requests received so far (method, uri, post, cookie) */
    public function requests(): array
    {
        $lines = file(self::stateDir().'/requests.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array();
        return array_map(fn ($line) => json_decode($line, true), $lines);
    }

    public function clearRequests(): void
    {
        file_put_contents(self::stateDir().'/requests.log', '');
    }

    private function save(): self
    {
        file_put_contents(self::stateDir().'/state.json', json_encode($this->state, JSON_PRETTY_PRINT));
        return $this;
    }
}
