<?php

class ExClient
{
    const BASE_URL = 'https://exhentai.org';

    private $ctr = 0;

    /** HTTP status and curl error of the last exec() request, for log messages */
    public $lastStatus = 0;
    public $lastError = '';

    /**
     * Optional config (used by the tests to point the client at a fake ExHentai server):
     *   "exhentai": {"baseUrl": "https://exhentai.org", "throttle": 3}
     * baseUrl replaces the https://exhentai.org prefix of every request URL (also of links taken from pages,
     * like the archiver link); throttle is the pause in seconds after every 5 requests (0 disables it).
     */
    protected static function setting($name, $default)
    {
        $config = Config::get();
        if (isset($config->exhentai) && isset($config->exhentai->$name)) {
            return $config->exhentai->$name;
        }

        return $default;
    }

    protected function url($url)
    {
        $base = rtrim((string)self::setting('baseUrl', self::BASE_URL), '/');
        if ($base !== self::BASE_URL && strpos($url, self::BASE_URL) === 0) {
            $url = $base.substr($url, strlen(self::BASE_URL));
        }

        return $url;
    }

    protected function throttle()
    {
        $this->ctr++;
        if ($this->ctr > 4) {
            $seconds = (int)self::setting('throttle', 3);
            if ($seconds > 0) {
                sleep($seconds);
            }
            $this->ctr = 0;
        }
    }

    /**
     * Categories ExHentai's f_cats filter leaves out (a bit set). Searches only list Doujinshi and Manga:
     * Misc 1, Artist CG 8, Game CG 16, Image Set 32, Cosplay 64, Asian Porn 128, Non-H 256, Western 512.
     */
    const SEARCH_EXCLUDED_CATEGORIES = 1017;

    /**
     * A search result page. $next is the cursor of the page (ExPage_Index::getNextCursor()), null for the first.
     */
    public function index($search = '', $next = null, $extraParams = array())
    {
        $params = array();
        if ($next !== null && $next !== '') {
            $params['next'] = $next;
        }

        if (is_array($extraParams)) {
            $params = array_merge($params, $extraParams);
        }

        if ($search) {
            $params = array_merge($params, array( //todo - move to config
                'f_cats' => self::SEARCH_EXCLUDED_CATEGORIES,
                'f_search' => $search
            ));
        }

        $url = self::BASE_URL . "/?" . http_build_query($params);
        return $this->exec($url);
    }

    public function tagSearch($search = '', $page = 0)
    {
        $url = sprinf("%s/tag/%s/%d", self::BASE_URL, $search, $page);
        return $this->exec($url);
    }

    public function gallery($id, $hash, $thumbPage = 0)
    {
        $url = sprintf('%s/g/%d/%s/?p=%d', self::BASE_URL, $id, $hash, $thumbPage);
        return $this->exec($url);
    }

    public function exec($url)
    {
        $this->throttle();

        $ch = curl_init($this->url($url));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        // No CURLOPT_FAILONERROR: ExHentai sends its "gallery not available" pages (copyright claim, removed)
        // with HTTP 404, and the callers need that page to tell why. A failed connection still returns false.
        curl_setopt($ch, CURLOPT_MAXREDIRS, 300);

        $cookie = Config::buildCookie();
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.63 Safari/537.36');
        
        $ret = curl_exec($ch);
        $this->lastStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $this->lastError = $ret === false ? curl_error($ch) : '';
        
        if (strpos($ret, 'Your IP address has been temporarily banned for using automated mirroring/harvesting software and/or failing to heed the overload warning.') !== false) {
            printf("Banned. Waiting a minute before retrying.\n");
            sleep(60*60);
            return $this->exec($url);
        }

        if (strpos($ret, 'You are opening pages too fast') !== false) {
            printf("Warned. Waiting 10 seconds before retying.\n");
            sleep(60*10);
            return $this->exec($url);
        }

        return $ret;
    }
    
    public function buttonPress($url)
    {
        if (strpos($this->exec($url), "dlcheck") !== false) {
            $this->throttle();
            
            $ch = curl_init($this->url($url));
            curl_setopt($ch, CURLOPT_POST, true);
            $post = array("dlcheck" => "true",
                          "dltype" => "org");
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);

            $cookie = Config::buildCookie();
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.63 Safari/537.36');
            
            $ret = curl_exec($ch);
            
            return $ret;
        } else {
            Log::debug("ExClient", "dlcheck bypassed already");
        }
        return "";
    }
    
    public function invalidateForm($url)
    {
        $ch = curl_init($this->url($url));
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, "invalidate_sessions=1");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        
        $cookie = Config::buildCookie();
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.63 Safari/537.36');
            
        $ret = curl_exec($ch);
    }
    
    public function getArchiveFileSize($url)
    {
        $ch = curl_init($this->url($url));
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        
        $cookie = Config::buildCookie();
        curl_setopt($ch, CURLOPT_COOKIE, $cookie);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/31.0.1650.63 Safari/537.36');
        
        $ret = curl_exec($ch);
        
        $result = "unknown";
        
        if ($ret) {
            $content_length = "unknown";
            $status = "unknown";

            if (preg_match( "/^HTTP\/1\.[01] (\d\d\d)/", $ret, $matches )) {
                $status = (int)$matches[1];
            }

            if (preg_match( "/Content-Length: (\d+)/", $ret, $matches )) {
                $content_length = (int)$matches[1];
            }

            if ($status == 200 || ($status > 300 && $status <= 308)) {
                $result = $content_length;
            }
        }
        
        return $result;
    }
}
