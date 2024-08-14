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
			echo realpath($file->getPathname()) . PHP_EOL;
			$bundle[$key] = hash_file('sha256', realpath($file->getPathname()));
		}
	}
}

// file_put_contents($bundleFile, base64_encode(json_encode($bundle)));

$file = "/Users/joeworkman/Developer/totalcms/resources/schemas/color.json";
echo hash_file('sha256', $file) . PHP_EOL;
echo $bundle['resources/schemas/color.json'] . PHP_EOL;
