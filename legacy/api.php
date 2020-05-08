<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

header('X-Robots-Tag: noindex');
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Total-Key, Content-Type, Accept, Cache-Control, X-Requested-With");

if (!function_exists('apache_request_headers')) {
    function apache_request_headers() {
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5)=="HTTP_") {
                $key=str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
                $out[$key]=$value;
            } else {
                $out[$key]=$value;
            }
        }
        return $out;
    }
}

// Allow Total CMS to function inside RapidWeaver
// by allowing CORS requests when coming from http://127.0.0.1
$header = apache_request_headers();
$origin = $header["Origin"] ?? $header["Referer"] ?? "";
if (strpos($origin, '127.0.0.1') !== false) {
    header("Access-Control-Allow-Origin:*");
}

require_once 'dynamics.php';

$settings = new Dynamics\Settings();
$app = new \Slim\App($settings->appConfig());

require 'Dynamics/API/dependencies.php';
require 'Dynamics/API/middleware.php';
require 'Dynamics/API/routes.php';

$app->run();
