<?php

const BASEDIR = __DIR__ . '/../';
$folders = [
	'resources/schemas',
	'src/Middleware',
	'src/Domain',
];
$bundleFile = __DIR__ . '/../resources/bundle';

$bundle = [];
foreach ($folders as $folder) {
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(BASEDIR . $folder));
	foreach ($files as $file) {
		if ($file->isFile()) {
			$key = $folder . '/' . $file->getFilename();
			$bundle[$key] = hash_file('sha256', $file->getPathname());
		}
	}
}

file_put_contents($bundleFile, base64_encode(json_encode($bundle)));
