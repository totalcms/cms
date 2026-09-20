<?php

declare(strict_types=1);

use TotalCMS\Domain\Admin\FormField\FormField;

/**
 * Option-label sorting must not depend on the machine's locale.
 *
 * `strnatcasecmp()` folds case byte by byte through the C library, which
 * consults LC_CTYPE. Under a UTF-8 locale the lead bytes of a multi-byte
 * character get Latin-1 case folding applied to them; under C/POSIX they do
 * not. Any option list with non-ASCII labels therefore came out in a
 * different order depending on where PHP was running — a developer machine
 * with LANG set sorted the locale picker one way, a CI container or a bare
 * server sorted it another.
 *
 * That is what broke the `settings: i18n` golden snapshot in CI while it
 * passed locally: the snapshot recorded the UTF-8 ordering and the runner
 * produced the C ordering.
 *
 * @see FormField::sortOptionsByLabel()
 */

/** @param array<int,array{value:string,label:string}> $options */
function sortLabels(array $options): array
{
	$sorted = (new ReflectionMethod(FormField::class, 'sortOptionsByLabel'))->invoke(null, $options);

	return array_column($sorted, 'label');
}

/**
 * Sort the same list under several locales, restoring whatever the process
 * had before. Returns one result per locale.
 *
 * @param  array<int,array{value:string,label:string}> $options
 *
 * @return array<string,array<int,string>>
 */
function sortLabelsAcrossLocales(array $options): array
{
	$restore = setlocale(LC_ALL, '0');
	$results = [];

	try {
		foreach (['C', 'en_US.UTF-8'] as $locale) {
			setlocale(LC_CTYPE, $locale);
			$results[$locale] = sortLabels($options);
		}
	} finally {
		// setlocale is process-global; a leak would reorder other workers' output.
		if (is_string($restore)) {
			setlocale(LC_ALL, $restore);
		}
	}

	return $results;
}

/** A spread of the scripts the locale registry actually ships. */
function scriptSampleOptions(): array
{
	$labels = ['Türkçe', 'Čeština', 'Ελληνικά', 'Русский', 'עברית', 'العربية', 'हिन्दी', 'ไทย', '中文 (CN)', '한국어', 'English (US)'];

	return array_map(static fn (string $l): array => ['value' => $l, 'label' => $l], $labels);
}

it('sorts non-ASCII option labels identically under every locale', function (): void {
	$results = sortLabelsAcrossLocales(scriptSampleOptions());

	expect($results['C'])->toBe($results['en_US.UTF-8']);
});

it('still sorts ASCII labels case-insensitively and naturally', function (): void {
	$options = array_map(
		static fn (string $l): array => ['value' => $l, 'label' => $l],
		['item10', 'Apple', 'item2', 'banana'],
	);

	expect(sortLabels($options))->toBe(['Apple', 'banana', 'item2', 'item10']);
});

it('leaves grouped (optgroup) structures untouched', function (): void {
	$grouped = ['Zebra' => [['value' => 'z', 'label' => 'Z']], 'Alpha' => [['value' => 'a', 'label' => 'A']]];

	$sorted = (new ReflectionMethod(FormField::class, 'sortOptionsByLabel'))->invoke(null, $grouped);

	expect(array_keys($sorted))->toBe(['Zebra', 'Alpha']);
});
