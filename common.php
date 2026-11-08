<?php

define('DS', DIRECTORY_SEPARATOR);

// Third party libraries (RedBeanPHP, Symfony DomCrawler, Intervention Image) are managed by Composer.
require __DIR__.DS.'vendor'.DS.'autoload.php';

// RedBeanPHP 5 lives in the RedBeanPHP namespace; keep the global "R" facade used throughout the code.
if (!class_exists('R', false)) {
	class_alias(\RedBeanPHP\R::class, 'R');
}

set_include_path(realpath('classes').PATH_SEPARATOR.get_include_path());

spl_autoload_register(function($className) {
	$className = str_replace("\\", DS, $className);
	$filename = str_replace('_', DS, $className).'.php';

	if(stream_resolve_include_path($filename)) {
		include $filename;
	}
});

if(php_sapi_name() !== 'cli') {
	//Auth::doAuth();
}

$config = Config::get();

R::setup($config->db->dsn, $config->db->user, $config->db->pass);
R::freeze(true);


?>
