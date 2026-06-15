#!/usr/bin/env php
<?php

/**
 * Generates a composer.json for the totalcms/cms distribution package.
 *
 * Reads the development composer.json, strips dev dependencies and scripts,
 * and outputs a manifest for the distribution zip (the update system).
 *
 * Packagist publishes the package from the repo's ROOT composer.json, so the
 * composer-plugin identity (`type` + `extra.class` + the `composer-plugin-api`
 * requirement — see src/Composer/Plugin.php) lives there and is the single
 * source of truth. This script just mirrors `type` and `extra` through rather
 * than redefining them, so the zip manifest can't drift from what Packagist
 * ships. (A root composer-plugin is safe for this repo: Composer never activates
 * the root package's own plugin — only when totalcms/cms is a dependency.)
 *
 * Usage: php bin/make-dist-composer.php [output-dir]
 */
$outputDir  = $argv[1] ?? __DIR__ . '/..';
$sourceFile = __DIR__ . '/../composer.json';

$source = json_decode((string)file_get_contents($sourceFile), true);
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
	'type'         => $source['type'] ?? 'library',
	'license'      => $source['license'] ?? 'proprietary',
	'keywords'     => $source['keywords'] ?? [],
	'repositories' => $repositories,
	'require'      => $source['require'] ?? [],
	'autoload'     => [
		'psr-4' => $source['autoload']['psr-4'] ?? [],
	],
	'bin'    => ['resources/bin/tcms'],
	'extra'  => $source['extra'] ?? [],
	'config' => [
		'sort-packages' => true,
		'platform'      => $source['config']['platform'] ?? ['php' => '8.2.0'],
	],
];

$json       = json_encode($dist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$outputPath = rtrim($outputDir, '/') . '/composer.json';

file_put_contents($outputPath, $json);
echo "Generated: {$outputPath}\n";
