<?php

// router to serve static files via CLI dev server
if (PHP_SAPI === 'cli-server') {
	// Stacks Internal PHP Preview server
	if (str_contains($_SERVER['DOCUMENT_ROOT'], 'RapidWeaver') || str_contains($_SERVER['DOCUMENT_ROOT'], 'Stacks')) {
		$_SERVER['APP_ENV'] = 'preview';
	}
}

if ($_SERVER['APP_ENV'] != 'preview') {
	// Some host servers stored the redirected URI with public in the path. This is a workaround to fix the path.
	$_SERVER['REQUEST_URI'] = str_replace('tcms/public', 'tcms', $_SERVER['REQUEST_URI']);
}

(require __DIR__ . '/../config/bootstrap.php')->run();
