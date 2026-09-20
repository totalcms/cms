<?php

declare(strict_types=1);

use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\SettingsSections;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

/**
 * An install declares the sections it owns in config/tcms.php:
 *
 *   $settings['siteId']       = 'italy';
 *   $settings['siteSettings'] = ['i18n', 'general'];
 *
 * The declaration governs BOTH directions. Only declared sections are layered
 * in from the overlay, so a key left behind after a section is undeclared is
 * inert rather than being read by something that no longer writes it.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	@mkdir(cmsDataDir() . '.system', 0755, true);
	$this->setUpApp(bootstrap());
	$this->diContainer = $this->app->getContainer();
});

/**
 * @param array<string,mixed> $base
 * @param array<string,mixed> $overlay
 * @param list<string>        $declared
 */
function declaredRepo(array $base, array $overlay, array $declared, string $siteId = 'italy'): SettingsRepository
{
	if ($base !== []) {
		file_put_contents(cmsDataDir() . '.system/settings.json', (string)json_encode($base));
	}
	if ($overlay !== []) {
		file_put_contents(cmsDataDir() . ".system/settings-{$siteId}.json", (string)json_encode($overlay));
	}

	$config               = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->siteId       = $siteId;
	$config->siteSettings = $declared;

	$c = test()->diContainer;

	return new SettingsRepository(
		$c->get(StorageAdapterInterface::class),
		$config,
	);
}

it('layers in a declared section from the overlay', function (): void {
	$repo = declaredRepo(
		['i18n' => ['default' => 'en_GB'], 'smtp' => ['host' => 'shared']],
		['i18n' => ['default' => 'it_IT']],
		['i18n'],
	);

	expect($repo->load()['i18n'])->toBe(['default' => 'it_IT']);
});

it('ignores an overlay key whose section is not declared', function (): void {
	// The whole point of governing reads: smtp sits in the overlay but this
	// site does not own smtp, so the shared value wins and the stale key is
	// inert.
	$repo = declaredRepo(
		['i18n' => ['default' => 'en_GB'], 'smtp' => ['host' => 'shared']],
		['i18n' => ['default' => 'it_IT'], 'smtp' => ['host' => 'stale']],
		['i18n'],
	);

	$effective = $repo->load();

	expect($effective['i18n'])->toBe(['default' => 'it_IT']);
	expect($effective['smtp'])->toBe(['host' => 'shared']);
});

it('answers ownership from the declaration, not from the overlay contents', function (): void {
	$repo = declaredRepo(
		['smtp' => ['host' => 'shared']],
		['smtp' => ['host' => 'stale']],
		['i18n'],
	);

	// Declared but absent from the file — still owned, so the first save lands
	// in the overlay instead of the shared file.
	expect($repo->ownsSection('i18n'))->toBeTrue();
	// Present in the file but not declared — not owned.
	expect($repo->ownsSection('smtp'))->toBeFalse();
});

it('treats a declared general section as all of its schema keys', function (): void {
	$repo = declaredRepo(
		['siteName' => 'EU Organization', 'timezone' => 'UTC', 'notfound' => '/404'],
		['siteName' => 'Ministero della Cultura', 'timezone' => 'Europe/Rome'],
		['general'],
	);

	$effective = $repo->load();

	expect($effective['siteName'])->toBe('Ministero della Cultura');
	expect($effective['timezone'])->toBe('Europe/Rome');
	expect($effective['notfound'])->toBe('/404');
	expect($repo->ownsSection('general'))->toBeTrue();
});

it('keeps loadOverlay() raw so a write does not drop undeclared keys', function (): void {
	// loadOverlay() is the WRITE side: SettingsSaver reads it, merges a section
	// in, and writes the whole array back. Filtering here would silently delete
	// every undeclared key on the next save.
	$repo = declaredRepo(
		['i18n' => ['default' => 'en_GB']],
		['i18n' => ['default' => 'it_IT'], 'smtp' => ['host' => 'stale']],
		['i18n'],
	);

	expect($repo->loadOverlay())->toBe([
		'i18n' => ['default' => 'it_IT'],
		'smtp' => ['host' => 'stale'],
	]);
});

it('owns nothing when siteSettings is empty', function (): void {
	$repo = declaredRepo(['i18n' => ['default' => 'en_GB']], ['i18n' => ['default' => 'it_IT']], []);

	expect($repo->ownsSection('i18n'))->toBeFalse();
	expect($repo->load()['i18n'])->toBe(['default' => 'en_GB']);
});

it('maps sections to the settings keys they own', function (): void {
	expect(SettingsSections::keysFor('smtp'))->toBe(['smtp']);

	$general = SettingsSections::keysFor('general');
	expect($general)->toContain('siteName');
	expect($general)->toContain('timezone');
	expect($general)->not->toContain('general');
});

it('filters an overlay down to the declared sections', function (): void {
	$filtered = SettingsSections::filterDeclared(
		['i18n' => ['a'], 'smtp' => ['b'], 'siteName' => 'X'],
		['i18n', 'general'],
	);

	expect(array_keys($filtered))->toBe(['i18n', 'siteName']);
});
