#!/usr/bin/env php
<?php

declare(strict_types=1);

// Performance-test fixtures (dev only — bin/ never ships).
//
//   php bin/perf-fixtures.php --size=N [--pages=100] [--seed=42] [--root=tmp/perf/N] [--force]
//       Build a self-contained install at --root: public/tcms.php pointing at
//       its own tcms-data/ and cache/, plus fixture.json. Benchmarks boot it
//       by setting DOCUMENT_ROOT to <root>/public.
//
//   php bin/perf-fixtures.php --size=N --site=/path/to/project [--pages=100] [--force]
//       Seed an existing Composer install (e.g. ~/Websites/acme.totalcms.co)
//       the way its own vendor/bin/tcms would boot.
//
// See tests/Performance/README.md.

use Tests\Performance\Fixtures\FixtureBuilder;
use TotalCMS\Support\Config;
use TotalCMS\TotalCMS;

if (PHP_SAPI !== 'cli') {
	exit(1);
}

$repo = dirname(__DIR__);

// Autoloading defines classes only; nothing boots until `new TotalCMS` below,
// after DOCUMENT_ROOT and TCMS_PROJECT_ROOT point at the target install.
require $repo . '/vendor/autoload.php';

$options = getopt('', ['size:', 'pages:', 'seed:', 'root:', 'site:', 'force']);

$size  = (int)($options['size'] ?? 0);
$pages = (int)($options['pages'] ?? 100);
$seed  = (int)($options['seed'] ?? 42);
$force = isset($options['force']);
$site  = isset($options['site']) ? perfAbsolute((string)$options['site']) : null;
$root  = perfAbsolute((string)($options['root'] ?? $repo . '/tmp/perf/' . $size));

if ($size < 1 || $pages < 0) {
	fwrite(STDERR, "usage: perf-fixtures.php --size=N [--pages=100] [--seed=42] [--root=DIR | --site=PROJECT] [--force]\n");
	exit(2);
}

/** Absolute, without a trailing slash: tcms.php and TCMS_PROJECT_ROOT must not depend on the cwd. */
function perfAbsolute(string $path): string
{
	$path = rtrim($path, '/');

	return str_starts_with($path, '/') ? $path : getcwd() . '/' . $path;
}

function perfFail(string $message): never
{
	fwrite(STDERR, $message . "\n");
	exit(1);
}

/** Remove a directory tree (no shell: plain PHP so it behaves the same everywhere). */
function perfRemoveTree(string $path): void
{
	if (!is_dir($path)) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);
	foreach ($items as $item) {
		$item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
	}
	rmdir($path);
}

if ($site === null) {
	// Only ever wipe a folder this script built: one holding fixture.json.
	if (is_dir($root) && (glob($root . '/*') ?: []) !== []) {
		if (!is_file($root . '/fixture.json')) {
			perfFail("{$root} is not empty and has no fixture.json, so it is not a fixture. Refusing to touch it.");
		}
		if (!$force) {
			perfFail("{$root} already holds a fixture. Re-run with --force to wipe it and rebuild.");
		}
		perfRemoveTree($root);
	}
	FixtureBuilder::writeDocroot($root);
}

// Boot exactly as production would for this install: settings read tcms.php
// from DOCUMENT_ROOT, writable dirs come from TCMS_PROJECT_ROOT.
$projectRoot              = $site ?? $root;
$_SERVER['DOCUMENT_ROOT'] = $projectRoot . '/public';
define('TCMS_PROJECT_ROOT', $projectRoot);

$started  = microtime(true);
$totalcms = new TotalCMS(false);

if ($site !== null) {
	// Existing site: replace only the fixture's own collection and pages, in
	// the data folder the site itself is configured with — and only when this
	// script put them there (fixture.json + the perf-post schema).
	$datadir    = $totalcms->container()->get(Config::class)->datadir;
	$collection = $datadir . '/' . FixtureBuilder::COLLECTION;
	if (is_dir($collection)) {
		$meta = json_decode((string)@file_get_contents($collection . '/.meta.json'), true);
		if (!is_file($site . '/fixture.json') || ($meta['schema'] ?? '') !== FixtureBuilder::SCHEMA) {
			perfFail("{$collection} was not created by perf-fixtures (no {$site}/fixture.json or schema is not " . FixtureBuilder::SCHEMA . '). Refusing to replace it.');
		}
		if (!$force) {
			perfFail("{$collection} already exists. Re-run with --force to replace it.");
		}
		perfRemoveTree($collection);
		foreach (glob($datadir . '/' . FixtureBuilder::PAGES . '/{page-,perf-}*.json', GLOB_BRACE) ?: [] as $file) {
			unlink($file);
		}
	}
}

$manifest = (new FixtureBuilder($totalcms->container(), $seed))->build($size, $pages);

// Written last: a crashed run leaves no manifest, so benchmarks skip it.
file_put_contents($projectRoot . '/fixture.json', $manifest->toJson());

printf("Built %d posts and %d pages in %s (%.1fs)\n", $size, $pages, $projectRoot, microtime(true) - $started);
