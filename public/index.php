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
	if (isset($_GET['route'])) {
		$filepath = __DIR__ . $_GET['route'];
		if (file_exists($filepath)) {
			// redirect to the static asset files in the public folder
			$path = rtrim($path, '/') . $_GET['route'];
			header("Location: $path");
			exit;
		}
	}
}

(require __DIR__ . '/../config/bootstrap.php')->run();
