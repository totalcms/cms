<?php

// router to serve static files via CLI dev server
if (PHP_SAPI === 'cli-server') {
	// Stacks Internal PHP Preview server
	if (str_contains($_SERVER['DOCUMENT_ROOT'], 'RapidWeaver') || str_contains($_SERVER['DOCUMENT_ROOT'], 'Stacks')) {
		$_SERVER['APP_ENV'] = 'preview';
	}
}

if (!isset($_SERVER['APP_ENV']) || $_SERVER['APP_ENV'] != 'preview') {
	// Some host servers stored the redirected URI with public in the path. This is a workaround to fix the path.
	$_SERVER['REQUEST_URI'] = str_replace('tcms/public', 'tcms', $_SERVER['REQUEST_URI']);
}

// Redirect app root to admin (trailing slash + base path prevents Slim route matching on /)
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$basePath    = str_replace('/public', '', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
if ($requestPath !== false && rtrim((string)$requestPath, '/') === rtrim($basePath, '/')) {
	header('Location: ' . rtrim((string)$requestPath, '/') . '/admin', true, 301);
	exit;
}

(require __DIR__ . '/../config/bootstrap.php')->run();
