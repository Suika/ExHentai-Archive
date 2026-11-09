<?php

use Symfony\Component\DomCrawler\Crawler;

/**
 * ExHentai search result page (front page / feed search).
 *
 * Works with every list view (Minimal, Minimal+, Compact, Extended, Thumbnail): galleries are the
 * /g/<gid>/<token>/ links inside the result list (.itg). Paging is cursor based: the "Next" link
 * (#dnext / #unext) carries ?next=<gid>; on the last page it is a plain <span>.
 */
class ExPage_Index extends ExPage_Abstract
{
    public function isLastPage()
    {
        return $this->getNextCursor() === null;
    }

    /**
     * @return string|null the "next" parameter of the next page, null on the last page
     */
    public function getNextCursor()
    {
        $link = $this->find('a#dnext, a#unext');
        if (count($link) === 0) {
            return null;
        }

        $query = array();
        parse_str((string)parse_url((string)self::attr($link, 'href'), PHP_URL_QUERY), $query);

        return isset($query['next']) && $query['next'] !== '' ? (string)$query['next'] : null;
    }

    public function getGalleries()
    {
        $ret = array();

        $links = $this->find('.itg a[href*="/g/"]');
        foreach ($links as $linkElem) {
            $link = new Crawler($linkElem);

            if (!preg_match('~https://exhentai\.org/g/(\d+)/(\w+)/~', (string)self::attr($link, 'href'), $matches)) {
                continue;
            }
            // the title; Compact/Extended views put the tags into the same link
            $title = $link->filter('.glink');
            $name = count($title) > 0 ? self::text($title->first()) : self::text($link);

            if (isset($ret[$matches[1]])) {
                // thumbnail and title link of the same gallery: keep the one with the title
                if (trim($ret[$matches[1]]->name) === '') {
                    $ret[$matches[1]]->name = $name;
                }
                continue;
            }

            $gallery = new stdClass();
            $gallery->name = $name;
            $gallery->exhenid = $matches[1];
            $gallery->hash = $matches[2];

            $ret[$matches[1]] = $gallery;
        }

        return array_values($ret);
    }
}
