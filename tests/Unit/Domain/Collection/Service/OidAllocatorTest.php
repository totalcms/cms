<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Collection\Service\OidAllocator;

/**
 * @param list<int> $claims numbers claimOid() hands out, in order
 */
function oidAllocatorWith(int $count, array $claims = []): OidAllocator
{
	$collection        = new CollectionData();
	$collection->id    = 'posts';
	$collection->count = $count;

	$fetcher = test()->createMock(CollectionFetcher::class);
	$fetcher->method('fetchCollection')->willReturn($collection);

	$saver = test()->createMock(CollectionSaver::class);
	$saver->expects(test()->exactly(count($claims)))->method('claimOid')->with('posts')->willReturnOnConsecutiveCalls(...$claims);

	return new OidAllocator($fetcher, $saver);
}

test('outside a create the next number is only a peek at the count', function (): void {
	$oids = oidAllocatorWith(4);

	expect($oids->next('posts'))->toBe(5);
	expect($oids->next('posts'))->toBe(5);
});

test('a create claims a number the first time it asks, and keeps it', function (): void {
	$oids    = oidAllocatorWith(4, [7]);
	$claimed = null;

	$seen = $oids->creating('posts', fn (): array => [$oids->next('posts'), $oids->next('posts')], $claimed);

	expect($seen)->toBe([7, 7]);
	expect($claimed)->toBeTrue();
});

test('a create that never asks for a number claims none', function (): void {
	$oids    = oidAllocatorWith(4);
	$claimed = null;

	$oids->creating('posts', fn (): string => 'named', $claimed);

	expect($claimed)->toBeFalse();
});

test('after a create the next number is a peek again', function (): void {
	$oids = oidAllocatorWith(4, [5]);
	$oids->creating('posts', fn (): int => $oids->next('posts'));

	expect($oids->next('posts'))->toBe(5);
});

test('a create that throws still ends its claim', function (): void {
	$oids = oidAllocatorWith(4, [5]);

	expect(fn () => $oids->creating('posts', function () use ($oids): void {
		$oids->next('posts');

		throw new RuntimeException('validation failed');
	}))->toThrow(RuntimeException::class);
	expect($oids->next('posts'))->toBe(5);
});
