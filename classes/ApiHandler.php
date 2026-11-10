<?php

use Intervention\Image\Encoders\GifEncoder;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;

class ApiHandler
{

    protected $params;
    protected $start;

    protected function galleriesAction()
    {
        $data = array();

        $page = $this->getParam('page', 0);
        $pagesize = $this->getParam('pagesize', 24);
        $search = $this->getParam('search');
        $order = $this->getParam('order', 'posted');
        $seed = $this->getParam('seed', 0);
        $unarchived = $this->getParam('unarchived', false);
        $read = $this->getParam('read', false);
        $color = $this->getParam('color', false);

        $seed = (int)base_convert($seed, 36, 10);

        $search = trim($search);

        $ret = Model_Gallery::search($page, $pagesize, $search, $order, $seed, $unarchived, $read, $color);
        $result = $ret['result'];
        $data['meta'] = $ret['meta'];
        $data['galleries'] = array();

        $data['end'] = false;
        if (($page + 1) * $pagesize >= $ret['meta']->total) {
            $data['end'] = true;
        }

        foreach ($result as $row) {
            $gallery = R::load('gallery', $row['id']);
            $export = $gallery->export();

            $thumb = $gallery->getThumbnail(0, Model_Gallery::THUMB_LARGE, false); //don't generate a thumb now
            if ($thumb) {
                $export['thumb'] = array(
                    'url' => $thumb->getUrl(),
                    'landscape' => ($thumb->width > $thumb->height)
                );
            }

            $export['tags'] = $gallery->exportTags();

            $export['posted_formatted'] = date('d/m/Y', strtotime($gallery->posted));

            $export['source'] = $gallery->exportSource();

            if (array_key_exists('ranked_weight', $row)) {
                $export['ranked_weight'] = $row['ranked_weight'];
            }

            $data['galleries'][] = $export;
        }

        $this->sendSuccess($data);
    }

    protected function galleryAction()
    {
        $id = $this->getParam('id');

        if ($id) {
            $gallery = R::load('gallery', $id);
            if (!$gallery->id) {
                $this->sendFail('Invalid gallery');
            }

            $data = $gallery->export();

            $data['tags'] = $gallery->exportTags();
            
            $data['source'] = $gallery->exportSource();

            $this->sendSuccess($data);
        } else {
            $this->sendFail('Invalid params');
        }
    }

    protected function archiveimageAction()
    {
        list($id, $index) = $this->getParams('id', 'index');
        $resize = $this->getParam('resize', false);

        if ($id && $index >= 0) {
            $gallery = R::load('gallery', $id);
            if ($gallery->id) {
                $entry = $gallery->getImageEntry($index);
                if ($entry) {
                    header('Pragma: public');
                    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $entry['mtime']) . ' GMT');
                    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
                    header('Cache-Control: max-age=31536000');

                    $ext = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));

                    if ($resize && is_numeric($resize) && (int)$resize > 0) {
                        $layer = ImageTool::loadData($gallery->getImageData($index));

                        if ($layer->width() < $resize) {
                            $resize = $layer->width();
                        }

                        $layer->scale(width: (int)$resize);

                        // resized pages keep their format (png, gif, webp), everything else becomes a JPEG
                        switch ($ext) {
                            case 'png':
                                $encoder = new PngEncoder();
                                $type = 'image/png';
                                break;
                            case 'gif':
                                $encoder = new GifEncoder();
                                $type = 'image/gif';
                                break;
                            case 'webp':
                                $encoder = new WebpEncoder(quality: 80);
                                $type = 'image/webp';
                                break;
                            default:
                                $encoder = new JpegEncoder(quality: 80);
                                $type = 'image/jpeg';
                        }

                        $data = $layer->encode($encoder)->toString();
                        header('Content-Type: '.$type);
                        echo $data;
                    } else {
                        header('Content-Type: '.self::imageContentType($ext));
                        $gallery->outputImage($index);
                    }
                } else {
                    http_response_code(404);
                }
            } else {
                http_response_code(404);
            }
        } else {
            http_response_code(400);
        }

        exit;
    }

    /**
     * Content type of a gallery page by its file extension (case-insensitive); unknown ones as before: JPEG
     */
    protected static function imageContentType($ext)
    {
        $types = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'bmp' => 'image/bmp',
        );

        return $types[strtolower($ext)] ?? 'image/jpeg';
    }

    /**
     * Content-Disposition for a download: an ASCII-only fallback name for old clients plus the real
     * (e.g. Japanese) name as RFC 5987 filename*. Quotes, slashes and control characters are replaced.
     */
    protected static function attachmentDisposition($filename)
    {
        $filename = preg_replace('~[\x00-\x1f\x7f"/\\\\]~', '_', (string)$filename);
        $ascii = preg_replace('~[^\x20-\x7e]~u', '_', $filename);

        return sprintf('attachment; filename="%s"; filename*=UTF-8\'\'%s', $ascii, rawurlencode($filename));
    }

    protected function gallerythumbAction()
    {
        list($id, $index, $type) = $this->getParams('id', 'index', 'type');

        if ($id && $index !== null && in_array($type, array(Model_Gallery::THUMB_LARGE, Model_Gallery::THUMB_SMALL))) {
            $gallery = R::load('gallery', $id);
            if ($gallery->id) {
                $thumb = $gallery->getThumbnail($index, $type, true);
                if ($thumb) {
                    header('Location: '.$thumb->getUrl());
                } else {
                    http_response_code(404);
                }
            } else {
                http_response_code(404);
            }
        } else {
            http_response_code(400);
        }

        exit;
    }

    protected function exgallerythumbAction()
    {
        $id = $this->getParam('id');

        if ($id) {
            $gallery = R::load('gallery', $id);
            if ($gallery->id) {
                $config = Config::get();
                $pageFile = sprintf('%s/pages/%d.html', $config->archiveDir, $gallery->exhenid);

                if (file_exists($pageFile)) {
                    $html = file_get_contents($pageFile);
                    $page = new ExPage_Gallery($html);

                    $url = $page->getThumbnailUrl();
                    if (!$url) {
                        http_response_code(404);
                        exit;
                    }

                    $ch = curl_init($url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                    curl_setopt($ch, CURLOPT_FAILONERROR, true);
                    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
                    // a web request: a slow or unreachable ExHentai must not hold the worker (B12)
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

                    $cookie = Config::buildCookie();
                    curl_setopt($ch, CURLOPT_COOKIE, $cookie);
                    curl_setopt($ch, CURLOPT_USERAGENT, ExClient::USER_AGENT);

                    $ret = curl_exec($ch);

                    $im = $ret ? @imagecreatefromstring($ret) : false;
                    if (!$im) {
                        // no cache headers: the browser asks again next time
                        http_response_code(502);
                        exit;
                    }

                    header('Content-Type: image/jpeg');
                    header('Last-Modified: '.gmdate('D, d M Y H:i:s', strtotime($gallery->posted)).' GMT');
                    header('Expires: '.gmdate('D, d M Y H:i:s', strtotime('+1 year')).' GMT');
                    header('Cache-Control: public');
                    imagejpeg($im);
                } else {
                    http_response_code(404);
                }
            } else {
                http_response_code(404);
            }
        } else {
            http_response_code(400);
        }

        exit;
    }

    protected function addgalleryAction()
    {
        list($gid, $hash) = $this->getParams('gid', 'token');

        if ($gid && $hash) {
            $gallery = Model_Gallery::addGallery($gid, $hash);
            $this->sendSuccess($gallery->export());
        } else {
            $this->sendFail('Invalid params');
        }
    }

    public function hasgalleryAction()
    {
        $gid = $this->getParam('gid');
        if ($gid) {
            $gallery = R::findOne('gallery', 'download = 1 and exhenid = ?', array($gid));
            if ($gallery) {
                $this->sendSuccess(array('exists' => true, 'id' => $gallery->id, 'archived' => !!$gallery->archived, 'deleted' => $gallery->deleted));
            } else {
                $this->sendSuccess(array('exists' => false));
            }
        } else {
            $this->sendFail('Invalid params');
        }
    }

    public function hasgalleriesAction()
    {
        $gids = $this->getParam('gids');
        if ($gids && is_array($gids)) {
            $result = R::getAll('select exhenid, archived, deleted from gallery where download = 1 and exhenid in ('.R::genSlots($gids).')', $gids);

            $this->sendSuccess($result);
        } else {
            $this->sendFail('Invalid params');
        }
    }

    public function deletegalleryAction()
    {
        list($id, $key) = $this->getParams('id', 'key');

        if (empty(Config::get()->allowDelete)) {
            $this->sendFail('Delete is disabled');
        } elseif ($key != Config::get()->accessKey) {
            $this->sendFail('Invalid access key');
        } else {
            $gallery = R::load('gallery', $id);
            if (!$gallery->id || $gallery->deleted >= 1) {
                $this->sendFail('Gallery not found or already deleted');
            } else {
                $gallery->deleted = 1;
                R::store($gallery);

                $this->sendSuccess(true);
            }
        }
    }

    public function downloadAction()
    {
        $id = $this->getParam('id');
        if ($id) {
            $gallery = R::load('gallery', $id);
            if ($gallery->id) {
                $archive = $gallery->getArchiveFilepath();
                if (file_exists($archive)) {
                    header('Content-Type: application/zip');
                    header('Content-Disposition: '.self::attachmentDisposition($gallery->name.'.zip'));
                    header('Content-Length: '.filesize($archive));
                    
                    // Stream in chunks: on PHP 8 readfile() of a large zip (e.g. 600 MB) can try to
                    // allocate the whole file and hit memory_limit.
                    while (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                    $fp = fopen($archive, 'rb');
                    while (!feof($fp)) {
                        echo fread($fp, 1048576);
                        flush();
                    }
                    fclose($fp);
                    exit;
                } else {
                    http_response_code(404);
                }
            } else {
                http_response_code(404);
            }
        } else {
            http_response_code(400);
        }
    }

    public function suggestedAction()
    {
        $term = $this->getParam('term');
        $result = Suggested::search($term);
        $this->sendSuccess($result);
    }

    public function indexerAction()
    {
        header('Content-Type: text/plain');

        // the index is kept up to date when galleries are stored; this rebuilds it completely (maintenance)
        set_time_limit(0);
        $count = Search::rebuild();
        echo "Search index rebuilt: $count galleries\n";
        exit;
    }

    public function statsAction()
    {
        $stats = Model_Gallery::getStats();
        
        $this->sendSuccess($stats);
    }

    /**
     * Health check: database and search index reachable.
     * (It used to call a gallery method that no longer exists and crashed.)
     */
    public function testAction()
    {
        $checks = array();

        try {
            R::getCell('select id from gallery limit 1'); // cheap: used by the Docker health check
            $checks['database'] = true;
        } catch (Throwable $e) {
            $checks['database'] = false;
        }

        try {
            R::getCell('select rowid from gallery_fts limit 1');
            $checks['search'] = true;
        } catch (Throwable $e) {
            $checks['search'] = false;
        }

        $this->sendSuccess($checks);
    }
    
    public function updateAction()
    {
        $id = $this->getParam('id');
        $status = $this->getParam('readStatus');
        
        $gallery = R::load('gallery', $id);
        
        if ($gallery->id) {
            $gallery->read = $status;
            R::store($gallery);

            $this->sendSuccess($gallery->read);
        } else {
            $this->sendFail();
        }
    }
    
    public function updateColorAction()
    {
        $id = $this->getParam('id');
        $color = $this->getParam('color');
        
        $gallery = R::load('gallery', $id);
        
        if ($gallery->id) {
            $gallery->color = $color;
            R::store($gallery);

            $this->sendSuccess($gallery->color);
        } else {
            $this->sendFail();
        }
    }

    protected function sendSuccess($data)
    {
        $data = array(
            'ret' => true,
            'data' => $data
        );

        $data['time'] = microtime(true) - $this->start;

        $this->sendResponse($data);
    }

    protected function sendFail($message = '')
    {
        $data = array(
            'ret' => false,
            'message' => $message
        );

        $this->sendResponse($data);
    }

    protected function sendResponse($resp)
    {
        $body = json_encode($resp);

        @ob_end_clean();

        if (function_exists('ob_gzhandler')) {
            ob_start('ob_gzhandler');
        }
        
        header('Access-Control-Allow-Origin: *');
        header('Content-Type: application/json');
        echo $body;

        ob_end_flush();

        exit;
    }

    protected function getParam($param, $default = null)
    {
        if (array_key_exists($param, $this->params)) {
            return $this->params[$param];
        } else {
            return $default;
        }
    }

    protected function getParams()
    {
        $params = func_get_args();
        $values = array();

        foreach ($params as $param) {
            $values[] = $this->getParam($param);
        }

        return $values;
    }

    public function handle($params)
    {
        if (!is_array($params) || !array_key_exists('action', $params)) {
            $this->sendFail('Invalid request');
        }

        $this->params = $params;

        $methodName = strtolower($params['action']).'Action';

        if (!method_exists($this, $methodName)) {
            $this->sendFail('Invalid action');
        }

        $this->start = microtime(true);

        $this->$methodName();

        $this->sendFail('Action fell through without sending a response');
    }
}
