<?php
require_once('vendor/autoload.php');

spl_autoload_register(function ($class) {
    $name = str_replace('\\', '/', $class);
    $include = __DIR__."/$name.php";
    if (!file_exists($include)) {
        return false;
    }
    require_once $include;
});
