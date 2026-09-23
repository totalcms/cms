<?php

declare(strict_types=1);

/**
 * Every shipped admin catalog carries exactly the keys en_US does. A key
 * added to one file and not the others renders its English default on every
 * other locale, which is how the file-field dialogs stayed English for two
 * releases while the depot dialogs were translated.
 */
$catalogs = array_map(
	fn (string $path): array => [basename($path, '.php')],
	glob(dirname(__DIR__, 4) . '/resources/translations/admin.*.php') ?: []
);

test('{0} carries the same keys as en_US', function (string $catalog): void {
	$dir  = dirname(__DIR__, 4) . '/resources/translations/';
	$en   = array_keys(require $dir . 'admin.en_US.php');
	$keys = array_keys(require $dir . $catalog . '.php');

	expect(array_values(array_diff($en, $keys)))->toBe([], "$catalog is missing keys")
		->and(array_values(array_diff($keys, $en)))->toBe([], "$catalog has keys en_US does not");
})->with($catalogs);
