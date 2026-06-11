#!/usr/bin/env php
<?php

/**
 * Generates a composer.json for the totalcms/cms distribution package.
 *
 * Reads the development composer.json, strips dev dependencies and scripts,
 * and outputs a composer-plugin package suitable for Composer distribution.
 *
 * The shipped package is a composer-plugin (not a plain library) so it can run
 * project-side lifecycle work on the customer's `composer install`/`update` —
 * see src/Composer/Plugin.php. The dev composer.json stays type=library so this
 * repo does not activate the plugin against itself; the plugin metadata
 * (type, extra.class, composer-plugin-api) is injected here at dist build.
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

// The plugin needs the composer-plugin-api constraint in `require`; merge it in
// without clobbering anything already declared in the source require.
$require = $source['require'] ?? [];
$require['composer-plugin-api'] ??= '^2.4';

$dist = [
	'name'         => 'totalcms/cms',
	'description'  => $source['description'] ?? 'Total CMS',
	'type'         => 'composer-plugin',
	'license'      => $source['license'] ?? 'proprietary',
	'keywords'     => $source['keywords'] ?? [],
	'repositories' => $repositories,
	'require'      => $require,
	'autoload'     => [
		'psr-4' => $source['autoload']['psr-4'] ?? [],
	],
	'bin'    => ['resources/bin/tcms'],
	'extra'  => [
		'class' => 'TotalCMS\\Composer\\Plugin',
	],
	'config' => [
		'sort-packages' => true,
		'platform'      => $source['config']['platform'] ?? ['php' => '8.2.0'],
	],
];

$json       = json_encode($dist, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$outputPath = rtrim($outputDir, '/') . '/composer.json';

file_put_contents($outputPath, $json);
echo "Generated: {$outputPath}\n";
