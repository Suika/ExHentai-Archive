<?php

use Symfony\Component\DomCrawler\Crawler;

class ExPage_Index extends ExPage_Abstract
{
    public function isLastPage()
    {
        return (count($this->find('td.ptds + td.ptdd')) > 0);
    }

    public function getGalleries()
    {
        $ret = array();

        $links = $this->find('.itg.gld .gl1t a');
        foreach ($links as $linkElem) {
            $gallery = new stdClass();

            $link = new Crawler($linkElem);
            $gallery->name = self::text($link);

            preg_match("~https://exhentai.org/g/(\d*)/(\w*)/~", (string)self::attr($link, 'href'), $matches);

            if (isset($matches[1]) && isset($matches[2])) {
                $gallery->exhenid = $matches[1];
                $gallery->hash = $matches[2];

                $ret[] = $gallery;
            }
        }

        return $ret;
    }
}
