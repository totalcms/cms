<?php
// spl_autoload_register(function($class) {
// 	$parts = explode("\\", $class);
// 	$name = end($parts);
// 	$include = "$name.php";
// 	if (!file_exists($include)) return false;
//     require_once $include;
// });
spl_autoload_register(function($class) {
	$name = str_replace('\\','/',$class);
	$include = __DIR__."/$name.php";
	if (!file_exists($include)) return false;
    require_once $include;
});
