<?php

// Accept optional base directory (e.g., "dist" for post-build verification).
// Composer forwards trailing args to every chained script, so `composer run
// test -- --filter=Mcp` would otherwise feed `--filter=Mcp` here as a path.
// Ignore anything starting with `-`.
$args  = array_slice($argv, 1);
$check = in_array('--check', $args, true);

$baseDirArg = '';
foreach ($args as $arg) {
	if (!str_starts_with($arg, '-')) {
		$baseDirArg = $arg;
		break;
	}
}

$baseDir = ($baseDirArg !== '')
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

// --check: compare without writing, and fail loudly.
//
// A stale manifest is invisible until it reaches production, where BundleChecker
// answers every request with "installation has been corrupted" naming one file —
// which is rarely the file that actually caused it. The test suite cannot catch
// it either, because the check is skipped under APP_ENV=test. So the only place
// this can be caught before a customer sees it is CI, comparing the committed
// manifest against the tree it claims to describe.
//
// Deliberately computed by the code above rather than a separate walker: a
// checker that reproduces the folder list or the exclusions would eventually
// disagree with the generator, and pass while the real bundle was wrong.
if ($check) {
	$stored = is_file($bundleFile)
		? json_decode((string)base64_decode((string)file_get_contents($bundleFile), true), true)
		: null;

	if (!is_array($stored)) {
		fwrite(STDERR, "Bundle check FAILED: resources/bundle is missing or unreadable.\n");
		fwrite(STDERR, "Run: composer run bundle\n");

		exit(1);
	}

	$changed = [];
	$missing = [];
	foreach ($bundle as $key => $hash) {
		if (!array_key_exists($key, $stored)) {
			$missing[] = $key;
		} elseif ($stored[$key] !== $hash) {
			$changed[] = $key;
		}
	}
	$removed = array_keys(array_diff_key($stored, $bundle));

	if ($changed === [] && $missing === [] && $removed === []) {
		echo 'Bundle check OK: ' . count($bundle) . " files match.\n";

		exit(0);
	}

	fwrite(STDERR, "Bundle check FAILED — resources/bundle does not match the tree.\n\n");
	foreach ([
		'modified since the bundle was built' => $changed,
		'not in the bundle'                   => $missing,
		'in the bundle but no longer present' => $removed,
	] as $label => $list) {
		if ($list === []) {
			continue;
		}
		fwrite(STDERR, sprintf("  %d %s:\n", count($list), $label));
		foreach (array_slice($list, 0, 20) as $entry) {
			fwrite(STDERR, "    {$entry}\n");
		}
		if (count($list) > 20) {
			fwrite(STDERR, sprintf("    … and %d more\n", count($list) - 20));
		}
		fwrite(STDERR, "\n");
	}
	fwrite(STDERR, "Run `composer run bundle` and commit the result.\n");

	exit(1);
}

echo "Building bundle...\n";
file_put_contents($bundleFile, base64_encode((string)json_encode($bundle)));
echo 'Bundle generated: ' . count($bundle) . " files hashed\n";
