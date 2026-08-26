<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\DepotField;
use TotalCMS\Domain\Admin\TotalForm;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * The depot browser's chrome is rendered server-side by DepotField, which had
 * no route to the translator at all — so the filter placeholder and the
 * Preview button stayed English on every localized site even though the
 * field's JS-driven confirm dialogs were already translated.
 *
 * TotalFormFactory now hands TotalForm a `translator` closure, and FormField
 * exposes t() on top of it. The English text moves to a default argument so
 * forms built without the factory (tests, extensions) never render raw keys.
 */
function translatorForLocale(string $locale): Closure
{
	$configRc = new ReflectionClass(Config::class);
	$config   = $configRc->newInstanceWithoutConstructor();
	$localeP  = $configRc->getProperty('locale');
	$localeP->setAccessible(true);
	$localeP->setValue($config, $locale);

	$service = new TranslationService($config, dirname(__DIR__, 5) . '/resources/translations');

	return $service->trans(...);
}

/** Build a TotalForm without running its 25-argument constructor. */
function formWithTranslator(?Closure $translator): TotalForm
{
	$rc   = new ReflectionClass(TotalForm::class);
	$form = $rc->newInstanceWithoutConstructor();

	$prop = $rc->getProperty('translator');
	$prop->setAccessible(true);
	$prop->setValue($form, $translator);

	return $form;
}

/** A mock form that renders no subfields but translates for real. */
function depotFormForLocale(?string $locale): TotalForm
{
	$real = formWithTranslator($locale === null ? null : translatorForLocale($locale));

	$test             = test();
	$form             = $test->createMock(TotalForm::class);
	$form->id         = '';
	$form->collection = 'test-collection';
	$form->api        = '/api';
	$form->method('isEditMode')->willReturn(false);
	$form->method('subField')->willReturn('');
	$form->method('field')->willReturn('');
	$form->method('t')->willReturnCallback(
		fn (string $key, string $default = '', array $params = []): string => $real->t($key, $default, $params)
	);

	return $form;
}

function renderDepot(?string $locale): string
{
	$field = new DepotField(form: depotFormForLocale($locale), name: 'mydepot', value: ['files' => []]);

	return $field->buildFormField();
}

describe('TotalForm::t', function (): void {
	test('resolves an admin-catalog key for the form locale', function (): void {
		$form = formWithTranslator(translatorForLocale('de_DE'));

		expect($form->t('depot.preview', 'Preview'))->toBe('Vorschau');
		expect($form->t('depot.filter_placeholder', 'Filter files...'))->toBe('Dateien filtern...');
	});

	test('falls back to the English default when no translator was injected', function (): void {
		$form = formWithTranslator(null);

		expect($form->t('depot.preview', 'Preview'))->toBe('Preview');
	});

	test('falls back to the English default for a key missing from every catalog', function (): void {
		$form = formWithTranslator(translatorForLocale('de_DE'));

		expect($form->t('depot.not_a_real_key', 'Preview'))->toBe('Preview');
	});
});

describe('DepotField chrome', function (): void {
	test('renders the filter placeholder and Preview button in the form locale', function (): void {
		$html = renderDepot('de_DE');

		expect($html)->toContain('placeholder="Dateien filtern..."');
		expect($html)->toContain('>Vorschau</button>');
	});

	test('renders English — never a raw key — when no translator is wired up', function (): void {
		$html = renderDepot(null);

		expect($html)->toContain('placeholder="Filter files..."');
		expect($html)->toContain('>Preview</button>');
		expect($html)->not->toContain('depot.filter_placeholder');
		expect($html)->not->toContain('depot.preview');
	});

	test('escapes the translated placeholder into the attribute', function (): void {
		$html = renderDepot('fr_FR'); // no catalog — falls back to en_US via the default

		expect($html)->toContain('placeholder="Filter files..."');
	});

	/**
	 * The rest of the field's chrome — action bar titles, the add-folder
	 * dialog, the preview headings — was hardcoded English long after the
	 * filter and Preview button were translated. These lock in the sweep.
	 *
	 * Only markup DepotField assembles itself is asserted: subField() is
	 * mocked to '', so the dialog field labels never reach the output here.
	 * Their keys are covered by the catalog-parity assertion below.
	 */
	test('renders the action bar and add-folder chrome in the form locale', function (): void {
		$html = renderDepot('de_DE');

		expect($html)->toContain('title="Dateiinformationen bearbeiten"');
		expect($html)->toContain('title="Download-Links"');
		expect($html)->toContain('title="Datei herunterladen"');
		expect($html)->toContain('title="Hochladen"');
		expect($html)->toContain('title="Neuer Ordner"');
		expect($html)->toContain('title="Datei löschen"');
		expect($html)->toContain('title="Depot-Schutz bearbeiten"');
		expect($html)->toContain('>Ordner hinzufügen</button>');
	});

	test('renders the file preview headings in the form locale', function (): void {
		$html = renderDepot('de_DE');

		expect($html)->toContain('<h6>Größe</h6>');
		expect($html)->toContain('<h6>Datum</h6>');
		expect($html)->toContain('<h6>Kommentare</h6>');
		expect($html)->toContain('<h6>Schlagwörter</h6>');
	});

	test('leaks no raw depot key anywhere in the rendered field', function (): void {
		foreach (['de_DE', 'es_ES', 'pl_PL', null] as $locale) {
			$html = renderDepot($locale);

			expect($html)->not->toMatch('/\bdepot\.[a-z_]+/');
			expect($html)->not->toContain('btn.close');
		}
	});

	/**
	 * Every key the field asks for must exist in en_US, or a locale that has
	 * no override silently falls through to the English default and the miss
	 * is invisible. Guards the sweep against a typo'd key.
	 */
	test('every key DepotField and DepotDropField request exists in the admin catalog', function (): void {
		$src = file_get_contents(dirname(__DIR__, 5) . '/src/Domain/Admin/FormField/DepotField.php')
			. file_get_contents(dirname(__DIR__, 5) . '/src/Domain/Admin/FormField/DepotDropField.php');

		preg_match_all('/->(?:t|esc)\(\s*\'([a-z][a-z0-9_.]*)\'/', (string)$src, $matches);

		$catalog = require dirname(__DIR__, 5) . '/resources/translations/admin.en_US.php';
		$keys    = array_unique($matches[1]);

		expect($keys)->not->toBeEmpty();
		foreach ($keys as $key) {
			expect($catalog)->toHaveKey($key);
		}
	});
});
