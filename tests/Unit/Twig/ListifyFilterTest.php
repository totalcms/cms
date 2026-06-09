<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Extension\TotalCMSTwigFilters;

test('listify splits a comma string, trims each item, drops empties', function (): void {
	expect(TotalCMSTwigFilters::listify('blog, gallery , news'))->toBe(['blog', 'gallery', 'news']);
});

test('listify drops blanks, trailing delimiters, and reindexes', function (): void {
	expect(TotalCMSTwigFilters::listify('blog, , news,'))->toBe(['blog', 'news']);
});

test('listify returns an empty array for empty or null input', function (): void {
	expect(TotalCMSTwigFilters::listify(''))->toBe([]);
	expect(TotalCMSTwigFilters::listify('   '))->toBe([]);
	expect(TotalCMSTwigFilters::listify(null))->toBe([]);
});

test('listify supports a custom delimiter', function (): void {
	expect(TotalCMSTwigFilters::listify('a; b ;c', ';'))->toBe(['a', 'b', 'c']);
});

test('listify falls back to comma when given an empty delimiter', function (): void {
	expect(TotalCMSTwigFilters::listify('a, b', ''))->toBe(['a', 'b']);
});
