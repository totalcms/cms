<?php

declare(strict_types=1);

/**
 * Every shipped JS catalog carries exactly the keys en_US does. AdminCatalogParityTest
 * only covers admin.*; a key missing from a js.* file shows its English default
 * on that locale's pages.
 */
$catalogs = array_map(
	fn (string $path): array => [basename($path, '.php')],
	glob(dirname(__DIR__, 4) . '/resources/translations/js.*.php') ?: []
);

test('{0} carries the same keys as en_US', function (string $catalog): void {
	$dir  = dirname(__DIR__, 4) . '/resources/translations/';
	$en   = array_keys(require $dir . 'js.en_US.php');
	$keys = array_keys(require $dir . $catalog . '.php');

	expect(array_values(array_diff($en, $keys)))->toBe([], "$catalog is missing keys")
		->and(array_values(array_diff($keys, $en)))->toBe([], "$catalog has keys en_US does not");
})->with($catalogs);
