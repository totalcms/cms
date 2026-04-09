<?php

echo "Building bundle...\n";

// Accept optional base directory (e.g., "dist" for post-build verification)
$baseDir = isset($argv[1]) ? rtrim($argv[1], '/') . '/' : __DIR__ . '/../';

$folders = [
	'config',
	'resources/schemas',
	'resources/templates',
	'src/Middleware',
	'src/Domain',
];
$bundleFile = $baseDir . 'resources/bundle';

$bundle = [];
foreach ($folders as $folder) {
	$fullPath = $baseDir . $folder;
	if (!is_dir($fullPath)) {
		continue;
	}
	$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fullPath));
	foreach ($files as $file) {
		if ($file->isFile()) {
			if ('.DS_Store' === $file->getFilename()) {
				continue;
			}
			if ($file->getFilename() === 'swagger.php') {
				continue;
			}
			$filePath     = $file->getPathname();
			$key          = (string) str_replace($baseDir, '', $filePath);
			$bundle[$key] = hash_file('sha256', $filePath);
		}
	}
}

file_put_contents($bundleFile, base64_encode((string) json_encode($bundle)));
echo "Bundle generated: " . count($bundle) . " files hashed\n";
