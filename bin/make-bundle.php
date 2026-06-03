<?php

echo "Building bundle...\n";

// Accept optional base directory (e.g., "dist" for post-build verification).
// Composer forwards trailing args to every chained script, so `composer run
// test -- --filter=Mcp` would otherwise feed `--filter=Mcp` here as a path.
// Ignore anything starting with `-`.
$baseDirArg = $argv[1] ?? '';
$baseDir    = ($baseDirArg !== '' && !str_starts_with($baseDirArg, '-'))
	? rtrim($baseDirArg, '/') . '/'
	: __DIR__ . '/../';

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
			// Our personal dev/test env configs are export-ignored from the
			// Composer dist (.gitattributes), so they must NOT be in the
			// integrity manifest either — BundleChecker throws "corrupted" for
			// any manifested file that's missing on a customer install.
			if ($file->getFilename() === 'local.dev.php' || $file->getFilename() === 'local.test.php') {
				continue;
			}
			$filePath     = $file->getPathname();
			$key          = (string)str_replace($baseDir, '', $filePath);
			$bundle[$key] = hash_file('sha256', $filePath);
		}
	}
}

file_put_contents($bundleFile, base64_encode((string)json_encode($bundle)));
echo 'Bundle generated: ' . count($bundle) . " files hashed\n";
