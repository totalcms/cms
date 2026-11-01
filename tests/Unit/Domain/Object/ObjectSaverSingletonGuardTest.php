<?php

declare(strict_types=1);

use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OidAllocator;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\DateFieldResetter;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;
use TotalCMS\Domain\Storage\FileLock;

/**
 * @param array<int,string> $existingIds
 */
function passThroughOidAllocator(): OidAllocator
{
	$oids = test()->createMock(OidAllocator::class);
	$oids->method('creating')->willReturnCallback(fn (string $collection, callable $fn): mixed => $fn());

	return $oids;
}

function makeObjectSaverWithSingletonDeps(CollectionData $collection, array $existingIds): ObjectSaver
{
	$factory = test()->createMock(ObjectFactory::class);
	$factory->method('generateObject')->willReturn(new ObjectData('second', []));

	$fetcher = test()->createMock(CollectionFetcher::class);
	$fetcher->method('fetchCollection')->willReturn($collection);

	$index = test()->createMock(IndexRepository::class);
	$index->method('fetchObjectIds')->willReturn($existingIds);

	return new ObjectSaver(
		test()->createMock(ObjectRepository::class),
		$factory,
		test()->createMock(PropertyDataProcessorInterface::class),
		test()->createMock(DateFieldResetter::class),
		new EventDispatcher(new NullLogger()),
		$fetcher,
		$index,
		systemWriteGate(),
		new FileLock(cmsDataDir()),
		passThroughOidAllocator(),
	);
}

test('saveObject rejects a second object in an active singleton', function (): void {
	$collection            = new CollectionData();
	$collection->id        = 'settings';
	$collection->schema    = 'settings';
	$collection->singleton = true;

	$saver = makeObjectSaverWithSingletonDeps($collection, ['settings']); // already has one

	expect(fn () => $saver->saveObject('settings', ['id' => 'second']))
		->toThrow(DomainException::class);
});

test('saveObject allows the first object in an empty singleton (lazy-create path)', function (): void {
	$collection            = new CollectionData();
	$collection->id        = 'settings';
	$collection->schema    = 'settings';
	$collection->singleton = true;

	$saver = makeObjectSaverWithSingletonDeps($collection, []); // empty

	// Does not throw the singleton guard (it may proceed to the normal save path).
	expect(fn () => $saver->saveObject('settings', ['id' => 'settings']))
		->not->toThrow(DomainException::class);
});
