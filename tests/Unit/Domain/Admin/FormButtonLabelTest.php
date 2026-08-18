<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\TotalFormFactory;
use TotalCMS\Domain\Translation\TranslationService;
use TotalCMS\Support\Config;

/**
 * Every admin form routes its `save` / `delete` options through
 * TotalFormFactory::buttonLabels(). Before this existed, each call site
 * hardcoded the English words "Save" and "Delete", so the two buttons on
 * every form in the admin stayed English no matter the operator's locale.
 *
 * `true` now means "default label" and resolves from the admin catalog.
 */
function makeFactoryForLocale(string $locale): TotalFormFactory
{
	$configRc = new ReflectionClass(Config::class);
	$config   = $configRc->newInstanceWithoutConstructor();
	$localeP  = $configRc->getProperty('locale');
	$localeP->setAccessible(true);
	$localeP->setValue($config, $locale);

	$factoryRc = new ReflectionClass(TotalFormFactory::class);
	$factory   = $factoryRc->newInstanceWithoutConstructor();
	$serviceP  = $factoryRc->getProperty('translationService');
	$serviceP->setAccessible(true);
	$serviceP->setValue($factory, new TranslationService(
		$config,
		dirname(__DIR__, 4) . '/resources/translations',
	));

	return $factory;
}

/**
 * @param array<string,mixed> $options
 *
 * @return array<string,mixed>
 */
function resolveButtons(TotalFormFactory $factory, array $options): array
{
	$method = new ReflectionMethod($factory, 'buttonLabels');
	$method->setAccessible(true);

	return $method->invoke($factory, $options);
}

describe('form button labels', function (): void {
	test('true resolves to the localized default label', function (): void {
		$resolved = resolveButtons(makeFactoryForLocale('de_DE'), [
			'save'   => true,
			'delete' => true,
		]);

		expect($resolved['save'])->toBe('Speichern');
		expect($resolved['delete'])->toBe('Löschen');
	});

	test('each shipped locale gets its own wording', function (string $locale, string $save, string $delete): void {
		$resolved = resolveButtons(makeFactoryForLocale($locale), ['save' => true, 'delete' => true]);

		expect($resolved['save'])->toBe($save);
		expect($resolved['delete'])->toBe($delete);
	})->with([
		['en_US', 'Save', 'Delete'],
		['es_ES', 'Guardar', 'Eliminar'],
		['it_IT', 'Salva', 'Elimina'],
		['nl_NL', 'Opslaan', 'Verwijderen'],
		['pl_PL', 'Zapisz', 'Usuń'],
	]);

	test('false hides the button', function (): void {
		$resolved = resolveButtons(makeFactoryForLocale('de_DE'), [
			'save'   => false,
			'delete' => false,
		]);

		// TotalForm renders no button for an empty label.
		expect($resolved['save'])->toBe('');
		expect($resolved['delete'])->toBe('');
	});

	test('an explicit string label is left untouched', function (): void {
		$resolved = resolveButtons(makeFactoryForLocale('de_DE'), [
			'save'   => 'Publish',
			'delete' => 'Discard',
		]);

		expect($resolved['save'])->toBe('Publish');
		expect($resolved['delete'])->toBe('Discard');
	});

	test('absent options stay absent so TotalForm keeps its own default', function (): void {
		$resolved = resolveButtons(makeFactoryForLocale('de_DE'), ['class' => 'my-form']);

		expect($resolved)->toBe(['class' => 'my-form']);
	});

	test('a truthy non-boolean still yields the default label', function (): void {
		// Guards against a permission helper returning 1 rather than true —
		// that must show the button, not silently hide it.
		$resolved = resolveButtons(makeFactoryForLocale('de_DE'), ['save' => 1]);

		expect($resolved['save'])->toBe('Speichern');
	});
});

describe('admin templates', function (): void {
	test('no template hardcodes the English Save/Delete button labels', function (): void {
		$root   = dirname(__DIR__, 4) . '/resources/templates';
		$files  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
		$guilty = [];

		foreach ($files as $file) {
			if ($file->getExtension() !== 'twig') {
				continue;
			}
			$contents = (string)file_get_contents($file->getPathname());
			if (preg_match('/(?:save|delete)\s*:\s*(?:.*\?\s*)?["\'](?:Save|Delete)["\']/', $contents) === 1) {
				$guilty[] = str_replace($root . '/', '', $file->getPathname());
			}
		}

		expect($guilty)->toBe([]);
	});
});
