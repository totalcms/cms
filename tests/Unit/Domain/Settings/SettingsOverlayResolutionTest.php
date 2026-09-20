<?php

declare(strict_types=1);

use TotalCMS\Support\Config;

/**
 * `siteId` names a per-install overlay merged over the shared settings.json,
 * so installs sharing one tcms-data can differ where they need to.
 *
 * The merge is SHALLOW: each top-level key the overlay declares replaces the
 * base's value outright. A bucket is replaced whole, never merged into — see
 * docs/planning/3.6/shared-data-settings.md §1.
 *
 * These drive the REAL config/settings.php rather than reimplementing its
 * merge, by pointing DOCUMENT_ROOT at a throwaway docroot whose tcms.php sets
 * `datadir` and `siteId`. config/settings.php already loads
 * `$_SERVER['DOCUMENT_ROOT'].'/tcms.php'`, and Config::init() keys its memo on
 * DOCUMENT_ROOT, so this is a supported way in — not a trick.
 */

/**
 * Resolve a real Config over temp settings files.
 *
 * @param  array<string,mixed> $base     contents of settings.json
 * @param  array<string,mixed> $overlay  contents of settings-{siteId}.json; [] writes no file
 * @param  list<string>        $declared sections this install declares via `siteSettings`
 */
function resolveWithOverlay(array $base, array $overlay, string $siteId = 'italy', array $declared = []): Config
{
	$root = sys_get_temp_dir() . '/tcms-overlay-' . uniqid();
	mkdir($root . '/data/.system', 0755, true);

	file_put_contents($root . '/data/.system/settings.json', (string)json_encode($base));
	if ($overlay !== []) {
		file_put_contents($root . '/data/.system/settings-' . $siteId . '.json', (string)json_encode($overlay));
	}
	file_put_contents($root . '/tcms.php', sprintf(
		'<?php return %s;',
		var_export(['datadir' => $root . '/data', 'siteId' => $siteId, 'siteSettings' => $declared], true),
	));

	$previousDocroot = $_SERVER['DOCUMENT_ROOT'] ?? null;

	try {
		$_SERVER['DOCUMENT_ROOT'] = $root;
		Config::reset();

		return Config::init();
	} finally {
		// DOCUMENT_ROOT and the Config memo are process-global; a leak would
		// point every later test at a deleted temp directory.
		if ($previousDocroot === null) {
			unset($_SERVER['DOCUMENT_ROOT']);
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $previousDocroot;
		}
		Config::reset();

		array_map('unlink', (array)glob($root . '/data/.system/*'));
		@unlink($root . '/tcms.php');
		@rmdir($root . '/data/.system');
		@rmdir($root . '/data');
		@rmdir($root);
	}
}

it('replaces a scalar the overlay declares and leaves the rest alone', function (): void {
	$config = resolveWithOverlay(
		['siteName' => 'EU Organization', 'notfound' => '/404'],
		['siteName' => 'Ministero della Cultura'],
		declared: ['general'],
	);

	expect($config->siteName)->toBe('Ministero della Cultura');
	expect($config->notfound)->toBe('/404');
});

it('replaces a whole bucket rather than merging into it', function (): void {
	$config = resolveWithOverlay(
		['i18n' => ['default' => 'en_GB', 'available' => ['en_GB', 'fr_FR']]],
		['i18n' => ['default' => 'it_IT', 'available' => ['it_IT']]],
		declared: ['i18n'],
	);

	expect($config->mergedSettings()['i18n'])->toBe(['default' => 'it_IT', 'available' => ['it_IT']]);
});

it('drops keys the overlay omits from a bucket it declares', function (): void {
	// The deliberate consequence of a shallow merge: declaring `available`
	// alone loses the shared `default` rather than inheriting it.
	$config = resolveWithOverlay(
		['i18n' => ['default' => 'en_GB', 'available' => ['en_GB']]],
		['i18n' => ['available' => ['it_IT']]],
		declared: ['i18n'],
	);

	expect($config->mergedSettings()['i18n'])->toBe(['available' => ['it_IT']]);
});

it('ignores a siteId that is not a plain slug, even when its overlay exists', function (): void {
	// Uppercase fails /^[a-z0-9-]+$/ but is a perfectly writable filename, so
	// the overlay really is sitting on disk and only the guard stops it being
	// read. (The previous version used a traversal string, which could not be
	// written at all — so it passed with or without the guard.)
	$config = resolveWithOverlay(
		['siteName' => 'EU Organization'],
		['siteName' => 'Should Not Apply'],
		'Italy',
		declared: ['general'],
	);

	expect($config->siteName)->toBe('EU Organization');
	expect($config->siteId)->toBe('');
});

it('refuses a traversal attempt in siteId', function (): void {
	// No fixture write here: the point is only that the value never reaches a
	// file path. Passing [] as the overlay keeps the helper from attempting an
	// impossible write and emitting a PHP warning.
	$config = resolveWithOverlay(['siteName' => 'EU Organization'], [], '../../etc/passwd');

	expect($config->siteId)->toBe('');
});

it('changes nothing when no overlay file exists', function (): void {
	$config = resolveWithOverlay(['siteName' => 'EU Organization'], []);

	expect($config->siteName)->toBe('EU Organization');
	expect($config->siteId)->toBe('italy');
});

it('survives a malformed overlay instead of taking the site down', function (): void {
	$root = sys_get_temp_dir() . '/tcms-overlay-bad-' . uniqid();
	mkdir($root . '/data/.system', 0755, true);
	file_put_contents($root . '/data/.system/settings.json', (string)json_encode(['siteName' => 'EU Organization']));
	file_put_contents($root . '/data/.system/settings-italy.json', '{ not json');
	file_put_contents($root . '/tcms.php', sprintf(
		'<?php return %s;',
		var_export(['datadir' => $root . '/data', 'siteId' => 'italy'], true),
	));

	$previousDocroot          = $_SERVER['DOCUMENT_ROOT'] ?? null;
	$_SERVER['DOCUMENT_ROOT'] = $root;
	Config::reset();

	try {
		expect(Config::init()->siteName)->toBe('EU Organization');
	} finally {
		if ($previousDocroot === null) {
			unset($_SERVER['DOCUMENT_ROOT']);
		} else {
			$_SERVER['DOCUMENT_ROOT'] = $previousDocroot;
		}
		Config::reset();
		array_map('unlink', (array)glob($root . '/data/.system/*'));
		@unlink($root . '/tcms.php');
		@rmdir($root . '/data/.system');
		@rmdir($root . '/data');
		@rmdir($root);
	}
});
