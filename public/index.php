<?php

// router to serve static files via CLI dev server
if (php_sapi_name() == 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (is_string($path) && file_exists($path)) {
        return false;
    }

    // Stacks Internal PHP Preview server
    if (str_contains($_SERVER['DOCUMENT_ROOT'], 'RapidWeaver') || str_contains($_SERVER['DOCUMENT_ROOT'], 'Stacks')) {
        $_SERVER['APP_ENV'] = 'preview';
    }
    if ($_GET['route']) {
        $path = __DIR__ . $_GET['route'];
        if (file_exists($path)) {
            $ext  = pathinfo($path, PATHINFO_EXTENSION);
            switch ($ext) {
                case 'css':
                    $mime = 'text/css';
                    break;
                case 'js':
                    $mime = 'application/javascript';
                    break;
                default:
                    $mime = mime_content_type($path);
            }

            header('Content-Type: ' . $mime);
            echo file_get_contents($path);
            exit;
        }
    }
}

(require __DIR__ . '/../config/bootstrap.php')->run();
