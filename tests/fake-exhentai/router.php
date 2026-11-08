<?php
/**
 * Fake ExHentai server for the integration tests, run with PHP's built-in web server:
 *   php -S 127.0.0.1:8090 tests/fake-exhentai/router.php
 *
 * The app is pointed at it through the "exhentai.baseUrl" config (ExClient). What it serves is controlled
 * by the tests through $FAKE_EXHENTAI_STATE/state.json (see Tests\Support\FakeExhentai):
 *
 *   /g/{gid}/{token}/        gallery page: fixture from state "galleries", 404 for unknown galleries
 *   /?page=N&...             feed index page N: fixture from state "index" (list, by page number)
 *   /archiver.php  GET       archiver page with the "dlcheck" form
 *   /archiver.php  POST      result page with the #continue link, rewritten to /archive/{gid}.zip
 *                            ("Insufficient Credits." when state "archiver" is "credits")
 *   /archive/{gid}.zip       the gallery archive from $FAKE_EXHENTAI_STATE/archives/{gid}.zip
 *
 * The archiver pages use tests/fixtures/exhentai/archiver-form.html / archiver-continue.html when they exist,
 * otherwise a minimal page with the same elements the app looks for.
 *
 * Every request is appended to $FAKE_EXHENTAI_STATE/requests.log (one JSON object per line).
 */

$stateDir = getenv('FAKE_EXHENTAI_STATE') ?: '/var/tmp/fake-exhentai';
$fixtureDir = dirname(__DIR__).'/fixtures/exhentai';

$state = array();
if (is_file($stateDir.'/state.json')) {
    $state = json_decode(file_get_contents($stateDir.'/state.json'), true) ?: array();
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$self = 'http://'.$_SERVER['HTTP_HOST'];

file_put_contents($stateDir.'/requests.log', json_encode(array(
    'method' => $method,
    'uri' => $uri,
    'post' => $_POST,
    'cookie' => $_SERVER['HTTP_COOKIE'] ?? '',
)).PHP_EOL, FILE_APPEND | LOCK_EX);

function fixture($name)
{
    global $fixtureDir;
    $file = $fixtureDir.'/'.basename($name);

    return is_file($file) ? file_get_contents($file) : null;
}

function send($body, $status = 200, $type = 'text/html; charset=UTF-8')
{
    http_response_code($status);
    header('Content-Type: '.$type);
    echo $body;
    exit;
}

// gallery page
if (preg_match('~^/g/(\d+)/(\w+)/?$~', $path, $m)) {
    $gallery = $state['galleries'][$m[1]] ?? null;
    if (!$gallery) {
        send('Key missing, or incorrect key provided.', 404);
    }

    $html = isset($gallery['html']) ? $gallery['html'] : fixture($gallery['fixture']);
    if ($html === null) {
        send('missing fixture '.$gallery['fixture'], 500);
    }

    // simple scenario edits, e.g. renaming a tag for the Audit tests
    foreach ($gallery['replace'] ?? array() as $from => $to) {
        $html = str_replace($from, $to, $html);
    }

    send($html, $gallery['status'] ?? 200);
}

// archiver
if ($path === '/archiver.php') {
    $gid = (int)($_GET['gid'] ?? 0);

    if ($method === 'POST') {
        if (($state['archiver'] ?? '') === 'credits') {
            send('<html><body><p>Insufficient Credits.</p></body></html>');
        }

        $target = $self.'/archive/'.$gid.'.zip?key=fake';
        $html = fixture('archiver-continue.html');
        if ($html === null) {
            $html = '<html><body><p>Downloads should start processing within a couple of seconds.</p>'
                .'<div id="continue"><a href="">Click Here To Start Downloading</a></div></body></html>';
        }
        $html = preg_replace('~(id="continue"[^>]*>.*?<a href=")[^"]*(")~s', '${1}'.$target.'${2}', $html, 1);

        send($html);
    }

    $html = fixture('archiver-form.html');
    if ($html === null) {
        $html = '<html><body><form method="post"><input type="hidden" name="dltype" value="org">'
            .'<input type="submit" name="dlcheck" value="Download Original Archive"></form></body></html>';
    }

    send($html);
}

// gallery archive
if (preg_match('~^/archive/(\d+)\.zip$~', $path, $m)) {
    $file = $stateDir.'/archives/'.$m[1].'.zip';
    if (!is_file($file)) {
        send('not found', 404, 'text/plain');
    }

    header('Content-Type: application/zip');
    header('Content-Length: '.filesize($file));
    readfile($file);
    exit;
}

// feed index (front page search)
if ($path === '/' || $path === '') {
    $page = (int)($_GET['page'] ?? 0);
    $name = $state['index'][$page] ?? null;
    if ($name === null) {
        send('<html><body><p>No hits found</p></body></html>');
    }

    $html = fixture($name);
    send($html ?? 'missing fixture '.$name, $html === null ? 500 : 200);
}

send('not found', 404, 'text/plain');
