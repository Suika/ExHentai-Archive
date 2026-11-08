<?php

use Symfony\Component\DomCrawler\Crawler;

abstract class ExPage_Abstract
{

    protected $doc;

    public function __construct($html)
    {
        $this->doc = new Crawler();
        $this->doc->addHtmlContent((string)$html, 'UTF-8');
    }

    public function getDocument()
    {
        return $this->doc;
    }

    /**
     * @return Crawler
     */
    public function find($selector)
    {
        return $this->doc->filter($selector);
    }

    /**
     * Text of all matched nodes, mirroring phpQuery's text():
     * the raw textContent of every node, with a "\n" appended to each non-empty
     * text when more than one node matched. Returns '' for an empty set.
     */
    protected static function text(Crawler $nodes)
    {
        $count = count($nodes);
        $return = '';

        foreach ($nodes as $node) {
            $text = $node->textContent;
            if ($count > 1 && $text) {
                $text .= "\n";
            }
            $return .= $text;
        }

        return $return;
    }

    /**
     * Attribute of the first matched node, mirroring phpQuery's attr():
     * '' for an empty set, null when the attribute is missing.
     */
    protected static function attr(Crawler $nodes, $attribute)
    {
        if (count($nodes) === 0) {
            return '';
        }

        return $nodes->attr($attribute);
    }

    /**
     * Next element sibling of the first matched node (phpQuery's next()).
     */
    protected static function next(Crawler $nodes)
    {
        if (count($nodes) === 0) {
            return $nodes;
        }

        return $nodes->nextAll()->first();
    }
}
