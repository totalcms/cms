<?php

declare(strict_types=1);

use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Storage\StorageAdapterInterface;
use TotalCMS\Support\Config;

/**
 * The repository is the only place that knows there are two settings files.
 *
 * `load()` is the READ side and returns effective values. `loadBase()` and
 * `loadOverlay()` are the WRITE side and must stay unmerged — SettingsSaver
 * loads, merges a posted section in, and writes the whole array back, so a
 * merged load there would copy every setting into whichever file it wrote.
 *
 * A Feature test over the real datadir rather than a faked filesystem:
 * StorageAdapterInterface declares fifteen methods, and a hand-rolled double
 * would couple this test to an interface neither this task nor the spec
 * touches.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	@mkdir(cmsDataDir() . '.system', 0755, true);
	$this->setUpApp(bootstrap());
	$this->diContainer = $this->app->getContainer();
});

/**
 * A repository over the test datadir with $siteId applied.
 *
 * @param array<string,mixed> $base     written to settings.json; [] writes no file
 * @param array<string,mixed> $overlay  written to settings-{siteId}.json; [] writes no file
 * @param list<string>        $declared sections this install declares via `siteSettings`
 */
function overlayRepo(array $base, array $overlay = [], string $siteId = 'italy', array $declared = ['i18n', 'general', 'smtp']): SettingsRepository
{
	if ($base !== []) {
		file_put_contents(cmsDataDir() . '.system/settings.json', (string)json_encode($base));
	}
	if ($overlay !== []) {
		file_put_contents(cmsDataDir() . ".system/settings-{$siteId}.json", (string)json_encode($overlay));
	}

	$config               = (new ReflectionClass(Config::class))->newInstanceWithoutConstructor();
	$config->siteId       = $siteId;
	$config->siteSettings = $siteId === '' ? [] : $declared;

	$c = test()->diContainer;

	return new SettingsRepository(
		$c->get(StorageAdapterInterface::class),
		$c->get(SettingsSchemaFetcher::class),
		$config,
	);
}

it('load() returns effective values', function (): void {
	$repo = overlayRepo(['siteName' => 'Shared', 'notfound' => '/404'], ['siteName' => 'Italy']);

	expect($repo->load())->toBe(['siteName' => 'Italy', 'notfound' => '/404']);
});

it('loadBase() and loadOverlay() stay unmerged', function (): void {
	$repo = overlayRepo(['siteName' => 'Shared', 'notfound' => '/404'], ['siteName' => 'Italy']);

	expect($repo->loadBase())->toBe(['siteName' => 'Shared', 'notfound' => '/404']);
	expect($repo->loadOverlay())->toBe(['siteName' => 'Italy']);
});

it('reports no overlay and loads base only when siteId is empty', function (): void {
	$repo = overlayRepo(['siteName' => 'Shared'], [], '');

	expect($repo->hasOverlay())->toBeFalse();
	expect($repo->loadOverlay())->toBe([]);
	expect($repo->load())->toBe(['siteName' => 'Shared']);
	expect($repo->overlayFilename())->toBe('');
});

it('names the overlay file after siteId', function (): void {
	expect(overlayRepo([], [], 'france')->overlayFilename())->toBe('settings-france.json');
});

it('expands general into its schema keys and passes other sections through', function (): void {
	$repo = overlayRepo([]);

	expect($repo->sectionKeys('smtp'))->toBe(['smtp']);

	$general = $repo->sectionKeys('general');
	expect($general)->toContain('siteName');
	expect($general)->toContain('timezone');
	expect($general)->not->toContain('general');
});

it('answers ownership from the declaration, not from what the overlay file contains', function (): void {
	$repo = overlayRepo(
		['smtp' => ['host' => 'shared'], 'siteName' => 'Shared'],
		['i18n' => ['default' => 'it_IT']],
		declared: ['i18n'],
	);

	expect($repo->ownsSection('i18n'))->toBeTrue();
	// smtp is not declared, even though nothing here says it isn't in the file.
	expect($repo->ownsSection('smtp'))->toBeFalse();
});

it('owns a declared general section', function (): void {
	$repo = overlayRepo(['siteName' => 'Shared'], ['timezone' => 'Europe/Rome'], declared: ['general']);

	expect($repo->ownsSection('general'))->toBeTrue();
});

it('saveOverlay() writes only the overlay file', function (): void {
	$repo = overlayRepo(['siteName' => 'Shared'], ['i18n' => ['default' => 'it_IT']]);

	$repo->saveOverlay(['i18n' => ['default' => 'en_GB']]);

	expect($repo->loadOverlay())->toBe(['i18n' => ['default' => 'en_GB']]);
	expect($repo->loadBase())->toBe(['siteName' => 'Shared']);
});

it('saveBase() writes only the base file', function (): void {
	$repo = overlayRepo(['siteName' => 'Shared'], ['i18n' => ['default' => 'it_IT']]);

	$repo->saveBase(['siteName' => 'Changed']);

	expect($repo->loadBase())->toBe(['siteName' => 'Changed']);
	expect($repo->loadOverlay())->toBe(['i18n' => ['default' => 'it_IT']]);
});

it('saveOverlay() refuses when no overlay is configured', function (): void {
	$repo = overlayRepo(['siteName' => 'Shared'], [], '');

	expect(fn (): mixed => $repo->saveOverlay(['siteName' => 'Nope']))
		->toThrow(RuntimeException::class);
});
