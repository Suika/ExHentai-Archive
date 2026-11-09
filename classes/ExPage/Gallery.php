<?php

class ExPage_Gallery extends ExPage_Abstract
{
    public function isValid()
    {
        $result = $this->find('h1#gn, div#taglist, div#gdd');
        return count($result) >= 3;
    }

    /**
     * @return string|null who claimed the gallery, when ExHentai shows "This gallery is unavailable due to a
     *                     copyright claim by <holder>."
     */
    public function getCopyrightClaim()
    {
        // the holder is the rest of the sentence, which ends the line ("... by Irodori Comics.<br />Sorry ...")
        foreach ($this->find('p') as $p) {
            foreach ($p->childNodes as $node) {
                if ($node->nodeType === XML_TEXT_NODE
                    && preg_match('/unavailable due to a copyright claim by (.+?)\.?\s*$/is', $node->textContent, $matches)) {
                    return trim(preg_replace('/\s+/', ' ', $matches[1]));
                }
            }
        }

        return null;
    }

    public function getName()
    {
        return self::text($this->find('h1#gn'));
    }

    public function getOriginalName()
    {
        return self::text($this->find('h1#gj'));
    }

    public function getType()
    {
        return self::text($this->find('div#gdc .cs'));
    }
    
    public function getThumbnailUrl()
    {
        $style = (string)self::attr($this->find('div#gd1 div'), 'style');
        
        preg_match('/url\((.*?)\)/', $style, $matches);
        
        return $matches[1] ?? null;
    }

    public function getTags()
    {
        $ret = array();

        $tagRows = $this->find('#taglist tr');
        foreach ($tagRows as $i => $tagRowElem) {
            $tagRow = $tagRows->eq($i);

            $tagNamespace = self::text($tagRow->filter('td:first-child'));
            $tagNamespace = trim($tagNamespace, ':');

            $tags = array();
            $tagLinks = $tagRow->filter('a');
            foreach ($tagLinks as $x => $tagLinkElem) {
                $tagLink = $tagLinks->eq($x);
                $tags[] = self::text($tagLink);
            }

            $ret[$tagNamespace] = $tags;
        }

        return $ret;
    }

    public function getProperties()
    {
        $ret = array();

        $attrs = $this->find('td.gdt1');
        foreach ($attrs as $i => $attrElem) {
            $attr = $attrs->eq($i);
            $propName = trim(self::text($attr), ':');
            $propValue = self::text(self::next($attr));

            $ret[$propName] = $propValue;
        }

        return $ret;
    }

    public function getArchiverUrl()
    {
        $elem = $this->find('a[onclick*="archiver.php"]');
        if (count($elem) > 0) {
            preg_match("~(https://exhentai.org/archiver.php.*)'~", (string)self::attr($elem, 'onclick'), $matches);
            if (count($matches) >= 2) {
                return $matches[1];
            }
        }

        return false;
    }

    public function getNewestVersion()
    {
        $elem = $this->find('div#gnd a')->last(); // was phpQuery's jQuery-only ':last'
        if (count($elem) === 1) {
            preg_match("~https://exhentai.org/g/(\d*)/(\w*)/~", (string)self::attr($elem, 'href'), $matches);

            $ret =new stdClass();
            $ret->exhenid = $matches[1];
            $ret->hash = $matches[2];

            return $ret;
        }

        return false;
    }
}
