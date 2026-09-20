<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;

/**
 * Across 19 sites nobody remembers which sections are local and which are
 * shared. The form says so, or the design is invisible and someone edits SMTP
 * believing it is local.
 *
 * Renders nothing at all on a single-site install — that is the invariant the
 * 18 settings golden snapshots enforce.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	$this->diContainer = $this->app->getContainer();
});

/**
 * SettingsForms wired to a repository that owns the declared sections.
 *
 * @param array<string,mixed> $overlay  contents of settings-italy.json
 * @param list<string>        $declared sections this install owns (defaults to `i18n`)
 */
function settingsFormsWithOverlay(array $overlay, array $declared = ['i18n']): TotalCMS\Domain\Admin\Form\SettingsForms
{
	@mkdir(cmsDataDir() . '.system', 0755, true);
	file_put_contents(cmsDataDir() . '.system/settings-italy.json', (string)json_encode($overlay));

	$c                     = test()->diContainer;
	$config                = (new ReflectionClass(TotalCMS\Support\Config::class))->newInstanceWithoutConstructor();
	$config->siteId        = 'italy';
	// Ownership comes from the declaration (Task 2b), not the overlay file:
	// `i18n` is owned so its page reads "site-specific"; `smtp` is not, so its
	// page reads "shared".
	$config->siteOverrides = $declared;

	// Two arguments: the schema-fetcher parameter was removed in 872f2eab9.
	$repo = new TotalCMS\Domain\Settings\Repository\SettingsRepository(
		$c->get(TotalCMS\Domain\Storage\StorageAdapterInterface::class),
		$config,
	);

	return new TotalCMS\Domain\Admin\Form\SettingsForms(
		$c->get(TotalFormFactory::class),
		$c->get(TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher::class),
		$c->get(TotalCMS\Domain\Settings\Services\SettingsFetcher::class),
		$c->get(TotalCMS\Domain\Translation\TranslationService::class),
		$c->get(TotalCMS\Domain\Extension\Service\ExtensionDiscovery::class),
		$c->get(TotalCMS\Domain\Extension\Service\ExtensionSettingsManager::class),
		$c->get(TotalCMS\Domain\Extension\Service\ExtensionManager::class),
		$c->get(TotalCMS\Support\Config::class),
		$repo,
	);
}

it('says nothing about scope when no overlay is configured', function (): void {
	$html = $this->diContainer->get(TotalFormFactory::class)->settings('i18n');

	expect($html)->not->toContain('settings-scope');
});

it('marks an owned section site-specific and names the file', function (): void {
	$forms = settingsFormsWithOverlay(['i18n' => ['default' => 'it_IT']]);

	$html = $forms->settings('i18n');

	expect($html)->toContain('settings-scope');
	expect($html)->toContain('settings-italy.json');
	expect($html)->toContain('Site-specific');
});

it('marks an unowned section shared', function (): void {
	$forms = settingsFormsWithOverlay(['i18n' => ['default' => 'it_IT']]);

	$html = $forms->settings('smtp');

	expect($html)->toContain('settings-scope');
	expect($html)->not->toContain('settings-italy.json');
	expect($html)->toContain('Shared by every site');
});

it('marks an owned formgrid section site-specific too', function (): void {
	// `general` declares a formgrid (unlike i18n/smtp above), which is exactly
	// the layout that dropped the scope line into the wrong grid cell — see
	// FIX 2. Nothing above would have caught that, since neither i18n nor smtp
	// uses a formgrid.
	$forms = settingsFormsWithOverlay(['siteName' => 'Ministero della Cultura'], ['general']);

	$html = $forms->settings('general');

	expect($html)->toContain('settings-scope');
	expect($html)->toContain('settings-italy.json');
	expect($html)->toContain('Site-specific');
});
