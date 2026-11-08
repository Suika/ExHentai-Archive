<?php

class ExPage_Archiver extends ExPage_Abstract
{
    public function getContinueUrl()
    {
        $elem = $this->find('#continue a');
        if (count($elem) >= 1) {
            return self::attr($elem, 'href');
        } else {
            return false;
        }
    }
}
