#!/usr/bin/env php
<?php

/**
 * Generates a composer.json for the totalcms/cms distribution package.
 *
 * Reads the development composer.json, strips dev dependencies and scripts,
 * and outputs a library-type package suitable for Composer distribution.
 *
 * Usage: php bin/make-dist-composer.php [output-dir]
 */

$outputDir = $argv[1] ?? __DIR__ . '/..';
$sourceFile = __DIR__ . '/../composer.json';

$source = json_decode((string) file_get_contents($sourceFile), true);
if (!is_array($source)) {
	fwrite(STDERR, "Error: Failed to read composer.json\n");
	exit(1);
}

// Test-only repository URLs to exclude
$testRepos = ['slim-test', 'pest-plugin-slim'];

$repositories = array_values(array_filter(
	$source['repositories'] ?? [],
	fn (array $repo): bool => !array_any(
		$testRepos,
		fn (string $test): bool => str_contains($repo['url'] ?? '', $test)
	)
));

$dist = [
	'name'         => 'totalcms/cms',
	'description'  => $source['description'] ?? 'Total CMS',
	'type'         => 'library',
	'license'      => $source['license'] ?? 'proprietary',
	'keywords'     => $source['keywords'] ?? [],
	'repositories' => $repositories,
	'require'      => $source['require'] ?? [],
	'autoload'     => [
		'psr-4' => $source['autoload']['psr-4'] ?? [],
	],
	'bin'    => ['resources/bin/tcms'],
	'config' => [
		'sort-packages' => true,
		'platform'      => $source['config']['platform'] ?? ['php' => '8.2.0'],
	],
];

$json = json_encode($dist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$outputPath = rtrim($outputDir, '/') . '/composer.json';

file_put_contents($outputPath, $json);
echo "Generated: {$outputPath}\n";
