<?php
// Define root path
defined('DS') ?: define('DS', DIRECTORY_SEPARATOR);
defined('ROOT') ?: define('ROOT', dirname(__DIR__) . DS);

// Load .env file
if (file_exists(ROOT . '.env')) {
    $dotenv = new \Dotenv\Dotenv(ROOT);
    $dotenv->load();
}

return [
    'displayErrorDetails' => true,

    'logger' => [
        'name' => 'totalcms',
        'rotate' => 30,
        'level' => \Monolog\Logger::DEBUG,
        'path' => "$this->site_root/tcms-data/totalcms.log",
    ],

    'cms_dir' => "tcms-data"
];
