<?php

declare(strict_types=1);

// may need to increase memory limit for tests in php.ini
ini_set('memory_limit', '1G');

// Set session save path to writable directory for CI environments
$sessionPath = sys_get_temp_dir() . '/php_sessions';
if (!is_dir($sessionPath)) {
	mkdir($sessionPath, 0777, true);
}
ini_set('session.save_path', $sessionPath);

require_once __DIR__ . '/../vendor/autoload.php';

// Define ROOT for CakePHP I18n translations (resources/locales/)
if (!defined('ROOT')) {
	define('ROOT', dirname(__DIR__));
}
