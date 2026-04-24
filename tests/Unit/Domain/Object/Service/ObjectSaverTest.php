<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\DateFieldResetter;
use TotalCMS\Domain\Object\Service\ObjectFactory;
use TotalCMS\Domain\Object\Service\ObjectSaver;
use TotalCMS\Domain\Property\Data\PropertyData;
use TotalCMS\Domain\Property\Service\PropertyDataProcessorInterface;

final class ObjectSaverTest extends TestCase
{
	private ObjectSaver $saver;
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $factory;
	private \PHPUnit\Framework\MockObject\MockObject $propertyProcessor;
	private \PHPUnit\Framework\MockObject\MockObject $dateFieldResetter;
	private EventDispatcher $eventDispatcher;

	/** @var array<string,mixed>|null */
	private ?array $dispatchedPayload = null;
	private ?string $dispatchedEvent = null;

	protected function setUp(): void
	{
		$this->storage           = $this->createMock(ObjectRepository::class);
		$this->factory           = $this->createMock(ObjectFactory::class);
		$this->propertyProcessor = $this->createMock(PropertyDataProcessorInterface::class);
		$this->dateFieldResetter = $this->createMock(DateFieldResetter::class);
		$this->eventDispatcher   = new EventDispatcher(new \Psr\Log\NullLogger());

		// Capture dispatched events
		$this->eventDispatcher->listen('object.created', function (array $payload): void {
			$this->dispatchedEvent   = 'object.created';
			$this->dispatchedPayload = $payload;
		});

		$this->saver = new ObjectSaver(
			$this->storage,
			$this->factory,
			$this->propertyProcessor,
			$this->dateFieldResetter,
			$this->eventDispatcher,
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
