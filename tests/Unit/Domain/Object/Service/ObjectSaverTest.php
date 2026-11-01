<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\OidAllocator;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\DateFieldResetter;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;
use TotalCMS\Domain\Storage\FileLock;

final class ObjectSaverTest extends TestCase
{
	private ObjectSaver $saver;
	private MockObject $storage;
	private MockObject $factory;
	private MockObject $propertyProcessor;
	private MockObject $dateFieldResetter;
	private MockObject $oids;
	private bool $insideCreate = false;
	private EventDispatcher $eventDispatcher;

	/** @var array<string,mixed>|null */
	private ?array $dispatchedPayload = null;
	private ?string $dispatchedEvent  = null;

	protected function setUp(): void
	{
		$this->storage           = $this->createMock(ObjectRepository::class);
		$this->factory           = $this->createMock(ObjectFactory::class);
		$this->propertyProcessor = $this->createMock(PropertyDataProcessorInterface::class);
		$this->dateFieldResetter = $this->createMock(DateFieldResetter::class);
		$this->eventDispatcher   = new EventDispatcher(new NullLogger());
		$this->oids              = $this->createMock(OidAllocator::class);
		$this->oids->method('creating')->willReturnCallback(function (string $collection, callable $fn): mixed {
			$this->insideCreate = true;
			try {
				return $fn();
			} finally {
				$this->insideCreate = false;
			}
		});

		// Capture dispatched events
		$this->eventDispatcher->listen('object.created', function (array $payload): void {
			$this->dispatchedEvent   = 'object.created';
			$this->dispatchedPayload = $payload;
		});

		// Non-singleton by default: fetchCollection returns null, so the
		// singleton guard is skipped and existing save behavior is unchanged.
		$this->saver = new ObjectSaver(
			$this->storage,
			$this->factory,
			$this->propertyProcessor,
			$this->dateFieldResetter,
			$this->eventDispatcher,
			$this->createMock(CollectionFetcher::class),
			$this->createMock(IndexRepository::class),
			systemWriteGate(),
			new FileLock(cmsDataDir()),
			$this->oids,
		);
	}

	public function testSaveObjectReturnsCreatedObject(): void
	{
		$object = $this->createTestObject('new-post');

		$this->factory->method('generateObject')->willReturn($object);
		$this->storage->method('existsObject')->willReturn(false);
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);

		$result = $this->saver->saveObject('posts', ['id' => 'new-post']);

		expect($result)->toBe($object);
		expect($result->id)->toBe('new-post');
	}

	public function testSaveObjectThrowsIfAlreadyExists(): void
	{
		$object = $this->createTestObject('existing-post');

		$this->factory->method('generateObject')->willReturn($object);
		$this->storage->method('existsObject')->willReturn(true);

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('Object with id existing-post already exists in posts');

		$this->saver->saveObject('posts', ['id' => 'existing-post']);
	}

	public function testSaveObjectResetsOnCreateDateFields(): void
	{
		$object = $this->createTestObject('new-post');

		$this->factory->method('generateObject')->willReturn($object);
		$this->storage->method('existsObject')->willReturn(false);
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);

		$this->dateFieldResetter
			->expects($this->once())
			->method('resetOnCreateFields')
			->with($object, 'posts');

		$this->saver->saveObject('posts', ['id' => 'new-post']);
	}

	public function testSaveObjectProcessesPropertiesBeforeSave(): void
	{
		$property          = $this->createMock(PropertyData::class);
		$processedProperty = $this->createMock(PropertyData::class);
		$object            = $this->createTestObject('new-post', [$property]);

		$this->factory->method('generateObject')->willReturn($object);
		$this->storage->method('existsObject')->willReturn(false);

		$this->propertyProcessor
			->expects($this->once())
			->method('processBeforeSave')
			->with($property)
			->willReturn($processedProperty);

		$this->saver->saveObject('posts', ['id' => 'new-post']);
	}

	public function testSaveObjectSavesToStorage(): void
	{
		$object = $this->createTestObject('new-post');

		$this->factory->method('generateObject')->willReturn($object);
		$this->storage->method('existsObject')->willReturn(false);
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);

		$this->storage
			->expects($this->once())
			->method('saveObject')
			->with('posts', $object);

		$this->saver->saveObject('posts', ['id' => 'new-post']);
	}

	public function testSaveObjectDispatchesCreatedEvent(): void
	{
		$object = $this->createTestObject('new-post');

		$this->factory->method('generateObject')->willReturn($object);
		$this->storage->method('existsObject')->willReturn(false);
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);

		$this->saver->saveObject('posts', ['id' => 'new-post']);

		expect($this->dispatchedEvent)->toBe('object.created');
		expect($this->dispatchedPayload)->not->toBeNull();
		expect($this->dispatchedPayload['collection'])->toBe('posts');
		expect($this->dispatchedPayload['id'])->toBe('new-post');
		expect($this->dispatchedPayload['object'])->toBe($object);
	}

	public function testSaveObjectDoesNotDispatchIfStorageThrows(): void
	{
		$object = $this->createTestObject('new-post');

		$this->factory->method('generateObject')->willReturn($object);
		$this->storage->method('existsObject')->willReturn(false);
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);
		$this->storage->method('saveObject')->willThrowException(new \RuntimeException('Disk full'));

		try {
			$this->saver->saveObject('posts', ['id' => 'new-post']);
		} catch (\RuntimeException) {
			// Expected
		}

		expect($this->dispatchedEvent)->toBeNull();
	}

	public function testTheIdIsGeneratedInsideTheAllocatorsCreate(): void
	{
		$generatedInside = null;
		$this->factory->method('generateObject')->willReturnCallback(function () use (&$generatedInside): ObjectData {
			$generatedInside = $this->insideCreate;

			return $this->createTestObject('note-7');
		});
		$this->storage->method('existsObject')->willReturn(false);
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);

		expect($this->saver->saveObject('posts', ['title' => 'Mine'])->id)->toBe('note-7');
		expect($generatedInside)->toBeTrue();
	}

	public function testAnImportDoesNotClaimANumber(): void
	{
		$this->eventDispatcher->suspendForImport('posts');
		$generatedInside = null;
		$this->factory->method('generateObject')->willReturnCallback(function () use (&$generatedInside): ObjectData {
			$generatedInside = $this->insideCreate;

			return $this->createTestObject('note-1');
		});
		$this->storage->method('existsObject')->willReturn(false);
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);

		$this->saver->saveObject('posts', ['title' => 'Imported']);

		expect($generatedInside)->toBeFalse();
	}

	public function testACreateThatFindsItsIdTakenUnderTheLockFails(): void
	{
		// Free at the first look, taken by the time the create lock is held.
		$looks = 0;
		$this->factory->method('generateObject')->willReturn($this->createTestObject('note-1'));
		$this->storage->method('existsObject')->willReturnCallback(function () use (&$looks): bool {
			return ++$looks > 1;
		});
		$this->propertyProcessor->method('processBeforeSave')->willReturnArgument(0);
		$this->storage->expects($this->never())->method('saveObject');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('Object with id note-1 already exists in posts');

		$this->saver->saveObject('posts', ['title' => 'Mine']);
	}

	/** @param array<PropertyData> $properties */
	private function createTestObject(string $id, array $properties = []): ObjectData
	{
		return new class($id, $properties) extends ObjectData {
			/** @param array<PropertyData> $properties */
			public function __construct(string $id, array $properties)
			{
				parent::__construct($id, []);
				$this->properties = new Collection($properties);
			}

			/** @return array<string,mixed> */
			public function toArray(): array
			{
				return ['id' => $this->id];
			}
		};
	}
}
