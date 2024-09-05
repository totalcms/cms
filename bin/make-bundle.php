<?php

echo "Building bundle...\n";

const BASEDIR = __DIR__ . '/../';
$folders      = [
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
			if ('.DS_Store' === $file->getFilename()) {
				continue;
			}
			$filePath     = $file->getPathname();
			$key          = str_replace(BASEDIR, '', $filePath);
			$bundle[$key] = hash_file('sha256', $filePath);
		}
	}
}

file_put_contents($bundleFile, base64_encode(json_encode($bundle)));
