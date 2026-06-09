<?php

declare(strict_types=1);

use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\SingletonCollectionResolver;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Service\ObjectCloner;
use TotalCMS\Domain\Object\Service\ObjectRemover;

function singletonCollection(string $id = 'settings', bool $flag = true): CollectionData
{
	$c = new CollectionData();
	$c->id = $id;
	$c->schema = $id;
	$c->singleton = $flag;

	return $c;
}

/**
 * @param array<int,string> $ids
 */
function makeResolver(array $ids, ?ObjectCloner $cloner = null, ?ObjectRemover $remover = null): SingletonCollectionResolver
{
	$index = test()->createMock(IndexRepository::class);
	$index->method('fetchObjectIds')->willReturn($ids);

	return new SingletonCollectionResolver(
		$index,
		$cloner ?? test()->createMock(ObjectCloner::class),
		$remover ?? test()->createMock(ObjectRemover::class),
	);
}

test('isActive is true for a singleton with 0 or 1 object, false above 1 or when flag off', function (): void {
	expect(makeResolver([])->isActive(singletonCollection()))->toBeTrue();
	expect(makeResolver(['settings'])->isActive(singletonCollection()))->toBeTrue();
	expect(makeResolver(['a', 'b'])->isActive(singletonCollection()))->toBeFalse();
	expect(makeResolver([])->isActive(singletonCollection('settings', false)))->toBeFalse();
});

test('objectId is always the collection id', function (): void {
	expect(makeResolver([])->objectId(singletonCollection('site')))->toBe('site');
});

test('resolveTarget returns null for an empty collection (routes to the new form)', function (): void {
	expect(makeResolver([])->resolveTarget(singletonCollection()))->toBeNull();
});

test('resolveTarget returns the collection id when the single object already matches', function (): void {
	$cloner = test()->createMock(ObjectCloner::class);
	$cloner->expects(test()->never())->method('cloneObject');

	expect(makeResolver(['settings'], $cloner)->resolveTarget(singletonCollection()))->toBe('settings');
});

test('resolveTarget re-keys a single mismatched-id object via clone + delete', function (): void {
	$cloner = test()->createMock(ObjectCloner::class);
	$cloner->expects(test()->once())->method('cloneObject')->with(
		['collection' => 'settings', 'id' => 'abc123'],
		['collection' => 'settings', 'id' => 'settings'],
	);
	$remover = test()->createMock(ObjectRemover::class);
	$remover->expects(test()->once())->method('deleteObject')->with('settings', 'abc123');

	expect(makeResolver(['abc123'], $cloner, $remover)->resolveTarget(singletonCollection()))->toBe('settings');
});
