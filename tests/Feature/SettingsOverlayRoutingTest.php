<?php

declare(strict_types=1);

use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSaver;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Settings\Services\SettingsValidator;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

/**
 * A save lands in the file that owns the section, and touches nothing else.
 *
 * The regression this guards: SettingsSaver loads the current settings,
 * merges the posted section in, and writes the whole array back. If it loaded
 * effective (merged) values it would copy every shared setting into the
 * overlay on the first save — silently forking the site's entire config.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	@mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/settings.json', (string)json_encode([
		'siteName' => 'EU Organization',
		'smtp'     => ['host' => 'mail.example.eu', 'port' => 587],
		'i18n'     => ['default' => 'en_GB', 'available' => ['en_GB']],
	]));
	file_put_contents(cmsDataDir() . '.system/settings-italy.json', (string)json_encode([
		'i18n' => ['default' => 'it_IT', 'available' => ['it_IT']],
	]));

	$this->setUpApp(bootstrap());
	$this->diContainer = $this->app->getContainer();

	// The container's Config was built before these files existed and without
	// a siteId; rebuild the repository around one that has it.
	//
	// siteOverrides is what makes a section owned (Task 2b) — the overlay file's
	// contents no longer decide it. `i18n` and `general` are owned here; `smtp`
	// deliberately is not, so the unowned-section case has something to use.
	$config                = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->siteId        = 'italy';
	$config->siteOverrides = ['i18n', 'general'];
	$this->repo            = new SettingsRepository(
		$this->diContainer->get(StorageAdapterInterface::class),
		$config,
	);
});

function overlayFile(): array
{
	return (array)json_decode((string)file_get_contents(cmsDataDir() . '.system/settings-italy.json'), true);
}

function baseFile(): array
{
	return (array)json_decode((string)file_get_contents(cmsDataDir() . '.system/settings.json'), true);
}

it('writes an owned section to the overlay and leaves the base alone', function (): void {
	$saver = new SettingsSaver(
		$this->diContainer->get(SettingsValidator::class),
		$this->diContainer->get(CacheManager::class),
		$this->repo,
	);

	$saver->saveSection('i18n', ['default' => 'it_IT', 'available' => ['it_IT', 'en_GB']]);

	expect(overlayFile()['i18n']['available'])->toBe(['it_IT', 'en_GB']);
	expect(baseFile()['i18n']['available'])->toBe(['en_GB']);
});

it('writes an unowned section to the base and leaves the overlay alone', function (): void {
	$saver = new SettingsSaver(
		$this->diContainer->get(SettingsValidator::class),
		$this->diContainer->get(CacheManager::class),
		$this->repo,
	);

	$saver->saveSection('smtp', ['host' => 'mail.italy.example.eu']);

	expect(baseFile()['smtp']['host'])->toBe('mail.italy.example.eu');
	expect(array_keys(overlayFile()))->toBe(['i18n']);
});

it('serves an overlaid siteName to readers that go through SettingsFetcher', function (): void {
	// SeoSettingsLoader reads general.siteName via SettingsFetcher. If the
	// fetcher were base-only, every country's SEO output would carry the
	// shared organisation name instead of its own.
	file_put_contents(cmsDataDir() . '.system/settings-italy.json', (string)json_encode([
		'siteName' => 'Ministero della Cultura',
	]));

	$fetcher = new SettingsFetcher(
		$this->repo,
		$this->diContainer->get(SettingsSchemaFetcher::class),
	);

	expect($fetcher->loadSection('general')['siteName'])->toBe('Ministero della Cultura');
});

it('never copies unrelated settings into the file it writes', function (): void {
	// The corruption case. Saving the overlay-owned i18n section must not pull
	// siteName or smtp — which live only in the base — into the overlay.
	$saver = new SettingsSaver(
		$this->diContainer->get(SettingsValidator::class),
		$this->diContainer->get(CacheManager::class),
		$this->repo,
	);

	$saver->saveSection('i18n', ['default' => 'it_IT', 'available' => ['it_IT']]);

	expect(overlayFile())->not->toHaveKey('siteName');
	expect(overlayFile())->not->toHaveKey('smtp');
});

it('deletes an owned section from the overlay and leaves the base intact', function (): void {
	$saver = new SettingsSaver(
		$this->diContainer->get(SettingsValidator::class),
		$this->diContainer->get(CacheManager::class),
		$this->repo,
	);

	$saver->deleteSection('i18n');

	// Gone from the file that owned it...
	expect(overlayFile())->not->toHaveKey('i18n');
	// ...and the shared value is untouched, so the other sites keep theirs.
	expect(baseFile()['i18n']['available'])->toBe(['en_GB']);
});
