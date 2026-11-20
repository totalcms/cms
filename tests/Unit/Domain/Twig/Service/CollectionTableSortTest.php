<?php

declare(strict_types=1);

use TotalCMS\Domain\Twig\Service\CollectionTableAccess;

/**
 * The admin collection table's starting sort, from the collection's Sort By
 * (the query's sort syntax).
 */
function tableSort(string $sortBy): string
{
	return (new ReflectionClass(CollectionTableAccess::class))->newInstanceWithoutConstructor()->sort($sortBy);
}

describe('a single property, as stored before Sort By took the syntax', function (): void {
	it('sorts ascending', function (): void {
		expect(tableSort('date'))->toBe('date:asc');
	});
});

describe('the sort syntax', function (): void {
	it('reads the shorthand and the colon format', function (): void {
		expect(tableSort('-date'))->toBe('date:desc')
			->and(tableSort('date:desc'))->toBe('date:desc')
			->and(tableSort('title:asc:natural'))->toBe('title:asc:natural');
	});

	it('keeps several properties in order', function (): void {
		expect(tableSort('date:desc,title'))->toBe('date:desc,title:asc')
			->and(tableSort(' -featured , title '))->toBe('featured:desc,title:asc');
	});
});

describe('nothing to sort by', function (): void {
	it('falls back to id', function (): void {
		expect(tableSort(''))->toBe('id:asc')
			->and(tableSort(' , '))->toBe('id:asc');
	});

	it('drops shuffle, which would reorder every page', function (): void {
		expect(tableSort('shuffle'))->toBe('id:asc')
			->and(tableSort('shuffle,title'))->toBe('title:asc');
	});
});
