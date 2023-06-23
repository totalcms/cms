<?php

// router to serve static files via CLI dev server
if (php_sapi_name() == 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_string($path) && file_exists($path)) {
        return false;
    }
}

(require __DIR__ . '/../config/bootstrap.php')->run();
