<?php
/**
 * PHPUnit bootstrap: loads the app classes the same way common.php does, without connecting to the
 * database (unit tests don't need one, integration tests talk to the running app over HTTP/CLI).
 */

define('APP_ROOT', dirname(__DIR__));
if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

chdir(APP_ROOT);

require APP_ROOT.'/vendor/autoload.php';

if (!class_exists('R', false)) {
    class_alias(\RedBeanPHP\R::class, 'R');
}

spl_autoload_register(function ($className) {
    $file = APP_ROOT.'/classes/'.str_replace(array('\\', '_'), '/', $className).'.php';
    if (is_file($file)) {
        include $file;
    }
});
