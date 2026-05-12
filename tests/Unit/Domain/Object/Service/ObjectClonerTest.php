<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Object\Service;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Event\EventDispatcher;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Repository\ObjectRepository;
use TotalCMS\Domain\Object\Service\DateFieldResetter;
use TotalCMS\Domain\Object\Service\ObjectCloner;

final class ObjectClonerTest extends TestCase
{
	private ObjectCloner $cloner;
	private \PHPUnit\Framework\MockObject\MockObject $storage;
	private \PHPUnit\Framework\MockObject\MockObject $dateFieldResetter;
	private EventDispatcher $eventDispatcher;

	/** @var array<string,mixed>|null */
	private ?array $dispatchedPayload = null;

	protected function setUp(): void
	{
		$this->storage           = $this->createMock(ObjectRepository::class);
		$this->dateFieldResetter = $this->createMock(DateFieldResetter::class);
		$this->eventDispatcher   = new EventDispatcher(new \Psr\Log\NullLogger());

		$this->eventDispatcher->listen('object.created', function (array $payload): void {
			$this->dispatchedPayload = $payload;
		});

		$this->cloner = new ObjectCloner(
			$this->storage,
			$this->dateFieldResetter,
			$this->eventDispatcher,
		);
	}

	public function testCloneObjectReturnsClonedObject(): void
	{
		$sourceObject = $this->createTestObject('source-id');

		$this->storage->method('fetchObject')->willReturn($sourceObject);
		$this->storage->method('existsObject')->willReturn(false);

		$result = $this->cloner->cloneObject(
			['collection' => 'posts', 'id' => 'source-id'],
			['collection' => 'posts', 'id' => 'cloned-id'],
		);

		expect($result->id)->toBe('cloned-id');
	}

	public function testCloneObjectThrowsWhenSourceNotFound(): void
	{
		$this->storage->method('fetchObject')->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Unable to find object to clone');

		$this->cloner->cloneObject(
			['collection' => 'posts', 'id' => 'missing'],
			['collection' => 'posts', 'id' => 'new-id'],
		);
	}

	public function testCloneObjectThrowsWhenDestinationExists(): void
	{
		$sourceObject = $this->createTestObject('source-id');

		$this->storage->method('fetchObject')->willReturn($sourceObject);
		$this->storage->method('existsObject')->willReturn(true);

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage('Object with id cloned-id already exists in posts');

		$this->cloner->cloneObject(
			['collection' => 'posts', 'id' => 'source-id'],
			['collection' => 'posts', 'id' => 'cloned-id'],
		);
	}

	public function testCloneObjectResetsDateFields(): void
	{
		$sourceObject = $this->createTestObject('source-id');

		$this->storage->method('fetchObject')->willReturn($sourceObject);
		$this->storage->method('existsObject')->willReturn(false);

		$this->dateFieldResetter
			->expects($this->once())
			->method('resetOnCreateFields');

		$this->dateFieldResetter
			->expects($this->once())
			->method('resetOnUpdateFields');

		$this->cloner->cloneObject(
			['collection' => 'posts', 'id' => 'source-id'],
			['collection' => 'archive', 'id' => 'cloned-id'],
		);
	}

	public function testCloneObjectSavesAndCopiesFiles(): void
	{
		$sourceObject = $this->createTestObject('source-id');

		$this->storage->method('fetchObject')->willReturn($sourceObject);
		$this->storage->method('existsObject')->willReturn(false);

		$this->storage
			->expects($this->once())
			->method('saveObject')
			->with('archive', $this->anything());

		$this->storage
			->expects($this->once())
			->method('copyObjectFiles')
			->with('posts', 'source-id', 'archive', 'cloned-id');

		$this->cloner->cloneObject(
			['collection' => 'posts', 'id' => 'source-id'],
			['collection' => 'archive', 'id' => 'cloned-id'],
		);
	}

	public function testCloneObjectDispatchesCreatedEvent(): void
	{
		$sourceObject = $this->createTestObject('source-id');

		$this->storage->method('fetchObject')->willReturn($sourceObject);
		$this->storage->method('existsObject')->willReturn(false);

		$result = $this->cloner->cloneObject(
			['collection' => 'posts', 'id' => 'source-id'],
			['collection' => 'archive', 'id' => 'cloned-id'],
		);

		expect($this->dispatchedPayload)->not->toBeNull();
		expect($this->dispatchedPayload['collection'])->toBe('archive');
		expect($this->dispatchedPayload['id'])->toBe('cloned-id');
		expect($this->dispatchedPayload['object'])->toBe($result);
	}

	public function testCloneObjectToSameCollection(): void
	{
		$sourceObject = $this->createTestObject('source-id');

		$this->storage->method('fetchObject')->willReturn($sourceObject);
		$this->storage
			->method('existsObject')
			->with('posts', 'copy-id')
			->willReturn(false);

		$result = $this->cloner->cloneObject(
			['collection' => 'posts', 'id' => 'source-id'],
			['collection' => 'posts', 'id' => 'copy-id'],
		);

		expect($result->id)->toBe('copy-id');
		expect($this->dispatchedPayload['collection'])->toBe('posts');
	}

	private function createTestObject(string $id): ObjectData
	{
		return new class($id) extends ObjectData {
			public function __construct(string $id)
			{
				parent::__construct($id, []);
				$this->properties = new Collection();
			}

			/** @return array<string,mixed> */
			public function toArray(): array
			{
				return ['id' => $this->id];
			}
		};
	}
}
