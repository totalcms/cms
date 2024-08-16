<?php

// router to serve static files via CLI dev server
if (PHP_SAPI === 'cli-server') {
	// Stacks Internal PHP Preview server
	if (str_contains($_SERVER['DOCUMENT_ROOT'], 'RapidWeaver') || str_contains($_SERVER['DOCUMENT_ROOT'], 'Stacks')) {
		$_SERVER['APP_ENV'] = 'preview';
	}
}

(require __DIR__ . '/../config/bootstrap.php')->run();
