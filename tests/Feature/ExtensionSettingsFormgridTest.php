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

// An extension's settings schema may lay its fields out with a formgrid, as
// core settings sections do. The permissions fieldset gets the top row, and
// an extension without a formgrid keeps the plain form.

beforeEach(function (): void {
	$this->setUpApp(bootstrap());
	$this->diContainer = $this->app->getContainer();
});

/** SettingsForms whose extension manager reports $permissions for every extension. */
function extensionSettingsForms(array $permissions): SettingsForms
{
	$c       = test()->diContainer;
	$manager = test()->createMock(ExtensionManager::class);
	$manager->method('getPermissions')->willReturn($permissions);

	return new SettingsForms(
		$c->get(TotalFormFactory::class),
		$c->get(SettingsSchemaFetcher::class),
		$c->get(SettingsFetcher::class),
		$c->get(TranslationService::class),
		$c->get(ExtensionDiscovery::class),
		$c->get(ExtensionSettingsManager::class),
		$manager,
		$c->get(Config::class),
		$c->get(SettingsRepository::class),
	);
}

/** The field names inside each fieldset, by legend, in page order. */
function fieldsetMembers(string $html): array
{
	$dom = new DOMDocument();
	@$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
	$out = [];
	foreach ($dom->getElementsByTagName('fieldset') as $fieldset) {
		$legend = $fieldset->getElementsByTagName('legend')->item(0)?->textContent ?? '';
		$names  = [];
		foreach ((new DOMXPath($dom))->query('.//*[@name]', $fieldset) ?: [] as $input) {
			$names[] = $input->getAttribute('name');
		}
		$out[$legend] = array_values(array_unique($names));
	}

	return $out;
}

test('a settings schema with a formgrid gets its fieldsets, with the permissions on top', function (): void {
	$html = extensionSettingsForms(['routes:admin' => true])->extensionSettings('totalcms/stock-photos');

	expect($html)
		->toContain('formgrid')
		->toMatch("/grid-template-areas:\\s*'ext-permissions'\\s*'formgrid-fieldset-1'\\s*'formgrid-fieldset-2'/")
		->toContain('<fieldset class="ext-permissions" style="grid-area: ext-permissions;">')
		->not->toContain('>Settings</h3>');

	expect(fieldsetMembers($html))->toMatchArray([
		'Library'      => ['galleries'],
		'Pixabay'      => ['pixabayKey', 'safeSearch'],
		'Search Panel' => ['defaultOrientation'],
	]);
});

test('without permissions the grid is the schema\'s own', function (): void {
	$html = extensionSettingsForms([])->extensionSettings('totalcms/stock-photos');

	expect($html)
		->toMatch("/grid-template-areas:\\s*'formgrid-fieldset-1'/")
		->not->toContain('ext-permissions');
});

test('a settings schema without a formgrid keeps the plain form', function (): void {
	$html = extensionSettingsForms(['routes:admin' => true])->extensionSettings('totalcms/pushover');

	expect($html)
		->not->toContain('grid-template-areas')
		->toContain('<fieldset class="ext-permissions">')
		->toContain('>Settings</h3>');
});
