<?php
setlocale(LC_ALL, 'C.UTF-8');
$locale = setlocale(LC_ALL, 0);
if ($locale !== 'C.UTF-8') {
    // A2 Hosting does not support C.UTF-8 trying to fallback
    setlocale(LC_ALL, "en_US.UTF-8");
}

if (!ini_get('date.timezone')) {
    date_default_timezone_set('Europe/London');
}
error_reporting(E_ALL);

// Hopeful fix for saving float values inside JSON
ini_set("serialize_precision", "-1");

if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
    $_SERVER['REMOTE_ADDR'] = $_SERVER["HTTP_CF_CONNECTING_IP"];
}

require_once 'autoload.php';
