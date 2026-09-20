<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\Form\SettingsForms;
use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Extension\Service\ExtensionDiscovery;
use TotalCMS\Domain\Extension\Service\ExtensionManager;
use TotalCMS\Domain\Extension\Service\ExtensionSettingsManager;
use TotalCMS\Domain\Settings\Repository\SettingsRepository;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Settings\Services\SettingsSchemaFetcher;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * The settings form must display the EFFECTIVE value of a setting, not the
 * shipped default.
 *
 * SettingsForms used to `require config/defaults.php` directly, which skips
 * the merge that config/settings.php performs (defaults -> tcms.php ->
 * settings.json). Anything an operator configured in tcms.php was therefore
 * invisible in the admin — the form rendered the shipped default instead.
 * Saving that form then wrote the default into settings.json, where it
 * outranks tcms.php, silently destroying the operator's configuration.
 *
 * For i18n that is not cosmetic: `available` is a list, and
 * SettingsSaver::deepMergeArrays replaces lists wholesale, so one visit to
 * Settings -> Internationalization followed by Save emptied the locale list
 * and broke every localized field on the site.
 */
beforeEach(function (): void {
	recursiveDelete(cmsDataDir());
	restoreFixtures();
	$this->setUpApp(bootstrap());
	// $app is protected on AppTestTrait, so stash the container the way
	// FormGoldenTest stashes its factory.
	$this->diContainer = $this->app->getContainer();
});

/**
 * Build SettingsForms against a Config carrying $overrides, standing in for
 * values an operator set in config/tcms.php. Every other dependency comes
 * from the real container.
 *
 * @param array<string,mixed> $overrides
 */
function settingsFormsWith(array $overrides): SettingsForms
{
	$settings = require dirname(__DIR__, 2) . '/config/settings.php';
	$settings = array_replace_recursive($settings, $overrides);

	$container = test()->diContainer;

	return new SettingsForms(
		$container->get(TotalFormFactory::class),
		$container->get(SettingsSchemaFetcher::class),
		$container->get(SettingsFetcher::class),
		$container->get(TranslationService::class),
		$container->get(ExtensionDiscovery::class),
		$container->get(ExtensionSettingsManager::class),
		$container->get(ExtensionManager::class),
		new Config($settings),
		$container->get(SettingsRepository::class),
	);
}

it('renders locales configured in tcms.php, which settings.json does not carry', function (): void {
	$html = settingsFormsWith(['i18n' => ['available' => ['it_IT', 'en_GB']]])->settings('i18n');

	// Every registry locale appears in this field as a selectable option, so
	// presence alone proves nothing — only `selected` distinguishes the
	// configured value from the 60-odd it is picked out of.
	expect($html)
		->toMatch('/<option[^>]*value="it_IT"[^>]*selected/')
		->toMatch('/<option[^>]*value="en_GB"[^>]*selected/')
		->not->toMatch('/<option[^>]*value="ja_JP"[^>]*selected/');
});

it('renders a general setting configured in tcms.php', function (): void {
	$forms = settingsFormsWith(['siteName' => 'Ministero della Cultura']);

	expect($forms->settings('general'))->toContain('Ministero della Cultura');
});

it('still falls back to the shipped default when nothing overrides it', function (): void {
	$forms = settingsFormsWith([]);

	// defaults.php ships maxDownloadSize = 2048 and nothing overrides it here.
	expect($forms->settings('general'))->toContain('2048');
});
