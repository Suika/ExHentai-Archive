<?php

class ExClient
{
    const BASE_URL = 'https://exhentai.org';

    private $ctr = 0;

    /** HTTP status and curl error of the last exec() request, for log messages */
    public $lastStatus = 0;
    public $lastError = '';

    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36';

    /**
     * Optional config, "exhentai" section (defaults in brackets):
     *   baseUrl           replaces the https://exhentai.org prefix of every request URL, also of links taken
     *                     from pages like the archiver link (tests point it at a fake ExHentai server)
     *   throttle          pause in seconds after every 5 requests, 0 disables it [3]
     *   connectTimeout    seconds to connect [30]
     *   requestTimeout    seconds for a whole page request [120]
     *   warnWait          seconds to wait after "You are opening pages too fast" [60]
     *   warnRetries       retries after that warning before giving up like on a ban [3]
     *   downloadTimeout   archive download: seconds without data before giving up [120] (ExArchiver)
     *   userAgent         [a current desktop Chrome]
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

    protected static function userAgent()
    {
        return (string)self::setting('userAgent', self::USER_AGENT);
    }

    /**
     * Throws when the response is ExHentai's ban page.
     *
     * @throws ExClient_BannedException
     */
    protected static function checkBan($response)
    {
        if (is_string($response) && stripos($response, 'Your IP address has been temporarily banned') !== false) {
            $expires = self::banExpiry($response);
            throw new ExClient_BannedException('IP temporarily banned by ExHentai'.($expires ? ', expires in '.$expires : ''));
        }
    }

    /**
     * @return string|null e.g. "2 hours and 13 minutes" from "The ban expires in 2 hours and 13 minutes"
     */
    public static function banExpiry($response)
    {
        $text = preg_replace('/\s+/', ' ', strip_tags((string)$response));
        if (preg_match('/ban expires in ((?:\d+ (?:days?|hours?|minutes?|seconds?)(?:,? and |, | )?)+)/i', $text, $matches)) {
            return trim(preg_replace('/(,? and |, )$/', '', trim($matches[1])));
        }

        return null;
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

    /**
     * Galleries with a tag, e.g. "female:glasses". $next is the page cursor like for index().
     */
    public function tagSearch($search = '', $next = null)
    {
        $url = sprintf('%s/tag/%s', self::BASE_URL, rawurlencode($search));
        if ($next !== null && $next !== '') {
            $url .= '?'.http_build_query(array('next' => $next));
        }
        return $this->exec($url);
    }

    public function gallery($id, $hash, $thumbPage = 0)
    {
        $url = sprintf('%s/g/%d/%s/?p=%d', self::BASE_URL, $id, $hash, $thumbPage);
        return $this->exec($url);
    }

    /**
     * GET request with the account cookies. Returns the page (also for HTTP errors like the 404 "gallery not
     * available" pages, see $lastStatus) or false when the request failed (see $lastError).
     *
     * @throws ExClient_BannedException when banned, or still warned about opening pages too fast after the retries
     */
    public function exec($url)
    {
        $retries = (int)self::setting('warnRetries', 3);

        for ($attempt = 0; ; $attempt++) {
            $this->throttle();

            $ch = curl_init($this->url($url));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            // No CURLOPT_FAILONERROR: ExHentai sends its "gallery not available" pages (copyright claim, removed)
            // with HTTP 404, and the callers need that page to tell why. A failed connection still returns false.
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)self::setting('connectTimeout', 30));
            curl_setopt($ch, CURLOPT_TIMEOUT, (int)self::setting('requestTimeout', 120));
            curl_setopt($ch, CURLOPT_COOKIE, Config::buildCookie());
            curl_setopt($ch, CURLOPT_USERAGENT, self::userAgent());

            $ret = curl_exec($ch);
            $this->lastStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $this->lastError = $ret === false ? curl_error($ch) : '';

            self::checkBan($ret);

            if (!is_string($ret) || strpos($ret, 'You are opening pages too fast') === false) {
                return $ret;
            }

            if ($attempt >= $retries) {
                throw new ExClient_BannedException(sprintf('still warned for opening pages too fast after %d retries', $retries));
            }

            $wait = (int)self::setting('warnWait', 60);
            Log::error('ExClient', 'Warned for opening pages too fast, waiting %d s (%d/%d)', $wait, $attempt + 1, $retries);
            if ($wait > 0) {
                sleep($wait);
            }
        }
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
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_FAILONERROR, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int)self::setting('connectTimeout', 30));
            curl_setopt($ch, CURLOPT_TIMEOUT, (int)self::setting('requestTimeout', 120));

            $cookie = Config::buildCookie();
            curl_setopt($ch, CURLOPT_COOKIE, $cookie);
            curl_setopt($ch, CURLOPT_USERAGENT, self::userAgent());
            
            $ret = curl_exec($ch);
            $this->lastStatus = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $this->lastError = $ret === false ? curl_error($ch) : '';

            self::checkBan($ret);
            
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
        curl_setopt($ch, CURLOPT_USERAGENT, self::userAgent());
            
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
        curl_setopt($ch, CURLOPT_USERAGENT, self::userAgent());
        
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
