<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Collection\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Repository\CollectionRepository;
use TotalCMS\Domain\Collection\Service\CollectionFactory;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionSaver;
use TotalCMS\Domain\Event\Service\EventDispatcher;
use TotalCMS\Domain\Index\Repository\IndexRepository;
use TotalCMS\Domain\License\Service\EditionFeatureService;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;

final class CollectionSaverTest extends TestCase
{
	private CollectionSaver $saver;
	private MockObject $repository;
	private MockObject $factory;
	private MockObject $indexRepository;
	private MockObject $collectionFetcher;
	private MockObject $editionFeatures;
	private MockObject $schemaFetcher;
	/** @var list<array<string,mixed>> what the saver handed the factory, i.e. what it stores */
	private array $generated = [];

	protected function setUp(): void
	{
		$this->repository         = $this->createMock(CollectionRepository::class);
		$this->repository->method('withMetaLock')->willReturnCallback(static fn (string $collection, callable $fn): mixed => $fn());
		$this->factory            = $this->createMock(CollectionFactory::class);
		$this->indexRepository    = $this->createMock(IndexRepository::class);
		$this->collectionFetcher  = $this->createMock(CollectionFetcher::class);
		$this->editionFeatures    = $this->createMock(EditionFeatureService::class);
		$this->schemaFetcher      = $this->createMock(SchemaFetcher::class);

		// Setup factory mock to return valid CollectionData from arrays
		$this->factory
			->method('generateCollection')
			->willReturnCallback(function (array $data): CollectionData {
				$this->generated[]        = $data;
				$collection               = new CollectionData();
				$collection->id           = $data['id'] ?? '';
				$collection->schema       = $data['schema'] ?? 'blog';
				$collection->count        = $data['count'] ?? 0;
				$collection->totalObjects = $data['totalObjects'] ?? 0;
				$collection->lastUpdated  = $data['lastUpdated'] ?? '';

				return $collection;
			});

		$this->saver = new CollectionSaver(
			$this->repository,
			$this->factory,
			$this->indexRepository,
			$this->collectionFetcher,
			$this->editionFeatures,
			new EventDispatcher(new NullLogger()),
			$this->schemaFetcher,
		);
	}

	/**
	 * @param list<string>      $properties
	 * @param list<string>|null $index      null indexes every property
	 */
	private function schemaWith(array $properties, ?array $index = null): SchemaData
	{
		$schema             = new SchemaData();
		$schema->properties = array_fill_keys($properties, ['type' => 'string']);
		$schema->index      = $index ?? $properties;

		return $schema;
	}

	private function storedCollection(string $schema, string $owner = ''): CollectionData
	{
		$collection                = new CollectionData();
		$collection->id            = 'portfolio';
		$collection->schema        = $schema;
		$collection->ownerProperty = $owner;
		$collection->lastUpdated   = '2025-01-01T00:00:00+00:00';

		return $collection;
	}

	/** @return array<string,array{mixed,bool}> */
	public static function publicRegistrationValues(): array
	{
		return [
			'string false'   => ['false', false],
			'string off'     => ['off', false],
			'garbage'        => ['garbage', false],
			'empty string'   => ['', false],
			'null'           => [null, false],
			'array'          => [['1'], false],
			'string on'      => ['on', true],
			'string true'    => ['true', true],
			'string 1'       => ['1', true],
			'string yes'     => ['yes', true],
			'int 1'          => [1, true],
			'bool true'      => [true, true],
			'bool false'     => [false, false],
		];
	}

	/**
	 * The serializer casts loosely ("false" would be stored as true), so the
	 * saver stores the strict normalised value the access middleware compares.
	 */
	#[DataProvider('publicRegistrationValues')]
	public function testSaveStoresPublicRegistrationStrictly(mixed $submitted, bool $stored): void
	{
		$this->saver->saveCollection(['id' => 'members', 'schema' => 'auth', 'publicRegistration' => $submitted]);

		expect($this->generated[0]['publicRegistration'])->toBe($stored);
	}

	public function testSaveStoresRegistrationGroupsAsAListOfNonEmptyStrings(): void
	{
		$this->saver->saveCollection(['id' => 'members', 'schema' => 'auth', 'registrationGroups' => [' vip ', '', 'vip', 42, 'member']]);
		$this->saver->saveCollection(['id' => 'members', 'schema' => 'auth', 'registrationGroups' => 'admin']);

		expect($this->generated[0]['registrationGroups'])->toBe(['vip', 'member']);
		expect($this->generated[1]['registrationGroups'])->toBe([]);
	}

	public function testPatchStoresPublicRegistrationStrictly(): void
	{
		$this->repository->method('fetchCollection')->willReturn($this->storedCollection('auth'));

		$this->saver->patchCollection('portfolio', ['publicRegistration' => 'false', 'registrationGroups' => 'admin']);

		$stored = end($this->generated);
		expect($stored['publicRegistration'])->toBeFalse();
		expect($stored['registrationGroups'])->toBe([]);
	}

	public function testSaveRejectsOwnerPropertyMissingFromSchema(): void
	{
		$this->schemaFetcher->method('fetchSchema')->with('portfolio-schema')->willReturn($this->schemaWith(['id', 'title']));
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerProperty 'owner' is not a property of schema 'portfolio-schema'. Add the property to the schema first.");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);
	}

	public function testSaveRejectsOwnerPropertyWhenSchemaCannotBeFetched(): void
	{
		$this->schemaFetcher->method('fetchSchema')->willThrowException(new \DomainException('Schema type does not exist: nope'));

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerProperty 'owner' is not a property of schema 'nope'.");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'nope', 'ownerProperty' => 'owner']);
	}

	public function testSaveAcceptsOwnerPropertyPresentInSchema(): void
	{
		$this->schemaFetcher->method('fetchSchema')->willReturn($this->schemaWith(['id', 'owner']));
		$this->repository->expects($this->once())->method('saveCollection');

		$result = $this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);

		expect($result->id)->toBe('portfolio');
	}

	public function testSaveRejectsAnOwnerPropertyThatIsNotIndexed(): void
	{
		// Member lists are scoped on the index; an unindexed owner property
		// would silently empty every member's list.
		$this->schemaFetcher->method('fetchSchema')->willReturn($this->schemaWith(['id', 'title', 'owner'], ['id', 'title']));
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerProperty 'owner' must be in the index of schema 'portfolio-schema' so member lists can be scoped. Add it to the schema's index first.");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);
	}

	public function testSaveRejectsAnOwnerPropertyWhoseTypeIsNotString(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['type' => 'boolean']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerProperty 'owner' must be a string property or a list of strings in schema 'portfolio-schema' (its type is 'boolean').");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);
	}

	public function testSaveAcceptsAListOwnerProperty(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['type' => 'array']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->once())->method('saveCollection');

		$result = $this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);

		expect($result->id)->toBe('portfolio');
	}

	public function testSaveAcceptsARefDeclaredListOwnerProperty(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['$ref' => 'https://www.totalcms.co/schemas/properties/list.json', 'field' => 'list']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->once())->method('saveCollection');

		$result = $this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);

		expect($result->id)->toBe('portfolio');
	}

	public function testSaveRejectsARefDeclaredImageOwnerProperty(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['$ref' => 'https://www.totalcms.co/schemas/properties/image.json', 'field' => 'image']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("(its type is 'object')");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);
	}

	public function testSaveRejectsAGalleryOwnerProperty(): void
	{
		// A gallery is an array of image objects: stamping ["alex"] into it
		// would break every member create.
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['$ref' => 'https://www.totalcms.co/schemas/properties/gallery.json', 'field' => 'gallery']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerProperty 'owner' must be a string property or a list of strings in schema 'portfolio-schema' (its type is 'array').");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);
	}

	public function testSaveRejectsAnInlineArrayOfObjectsOwnerProperty(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['type' => 'array', 'items' => ['type' => 'object']]];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("(its type is 'array')");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);
	}

	public function testSaveAcceptsAnOwnerPropertyTypedAsStringOrNull(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['type' => ['string', 'null']]];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->once())->method('saveCollection');

		$result = $this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);

		expect($result->id)->toBe('portfolio');
	}

	public function testSaveRejectsAnOwnerPropertyTypedAsIntegerOrNull(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['type' => ['integer', 'null']]];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("(its type is 'integer')");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);
	}

	public function testSaveAcceptsAnOwnerPropertyWithNoDeclaredType(): void
	{
		$schema             = $this->schemaWith(['id', 'owner']);
		$schema->properties = ['id' => ['type' => 'string'], 'owner' => ['field' => 'input']];
		$this->schemaFetcher->method('fetchSchema')->willReturn($schema);
		$this->repository->expects($this->once())->method('saveCollection');

		$result = $this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => 'owner']);

		expect($result->id)->toBe('portfolio');
	}

	public function testSaveWithEmptyOwnerPropertySkipsSchemaCheck(): void
	{
		$this->schemaFetcher->expects($this->never())->method('fetchSchema');
		$this->repository->expects($this->once())->method('saveCollection');

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerProperty' => '']);
	}

	public function testPatchRejectsOwnerPropertyTypo(): void
	{
		$this->repository->method('fetchCollection')->willReturn($this->storedCollection('portfolio-schema'));
		$this->schemaFetcher->method('fetchSchema')->willReturn($this->schemaWith(['id', 'owner']));
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerProperty 'typo' is not a property");

		$this->saver->patchCollection('portfolio', ['ownerProperty' => 'typo']);
	}

	public function testUpdateRejectsSchemaRenameThatLosesOwnerProperty(): void
	{
		$this->repository->method('fetchCollection')->willReturn($this->storedCollection('portfolio-schema', 'owner'));
		$this->schemaFetcher->method('fetchSchema')->with('other-schema')->willReturn($this->schemaWith(['id']));

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerProperty 'owner' is not a property of schema 'other-schema'");

		$this->saver->updateCollection('portfolio', ['id' => 'portfolio', 'schema' => 'other-schema', 'ownerProperty' => 'owner']);
	}

	public function testMetadataBumpSkipsSchemaCheckWhenOwnerAndSchemaUnchanged(): void
	{
		$this->repository->method('fetchCollection')->willReturn($this->storedCollection('portfolio-schema', 'owner'));
		$this->schemaFetcher->expects($this->never())->method('fetchSchema');
		$this->repository->expects($this->once())->method('saveCollection');

		$this->saver->incrementTotalObjects('portfolio');
	}

	private function authCollection(string $id, string $schema): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $schema;

		return $collection;
	}

	public function testSaveAcceptsAnAuthOwnerCollection(): void
	{
		$this->collectionFetcher->method('fetchCollection')->with('members')->willReturn($this->authCollection('members', 'auth'));
		$this->repository->expects($this->once())->method('saveCollection');

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerCollection' => 'members']);
	}

	public function testSaveAcceptsAnOwnerCollectionWhoseSchemaInheritsAuth(): void
	{
		$this->collectionFetcher->method('fetchCollection')->with('members')->willReturn($this->authCollection('members', 'member'));
		$schema              = new SchemaData();
		$schema->inheritFrom = ['auth'];
		$this->schemaFetcher->method('fetchRawSchema')->with('member')->willReturn($schema);
		$this->repository->expects($this->once())->method('saveCollection');

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerCollection' => 'members']);
	}

	public function testSaveRejectsAMissingOwnerCollection(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerCollection 'members' is not an auth collection. Choose a collection whose schema is auth or inherits from it.");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerCollection' => 'members']);
	}

	public function testSaveRejectsANonAuthOwnerCollection(): void
	{
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->authCollection('blog', 'blog'));
		$this->schemaFetcher->method('fetchRawSchema')->willReturn(new SchemaData());
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerCollection 'blog' is not an auth collection.");

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerCollection' => 'blog']);
	}

	public function testSaveAcceptsAnAuthAccessCollection(): void
	{
		$this->collectionFetcher->method('fetchCollection')->with('dealers')->willReturn($this->authCollection('dealers', 'auth'));
		$this->repository->expects($this->once())->method('saveCollection');

		$this->saver->saveCollection(['id' => 'price-sheets', 'schema' => 'portfolio-schema', 'accessCollection' => 'dealers']);
	}

	public function testSaveRejectsANonAuthAccessCollection(): void
	{
		// Visitors would be sent to a login that cannot sign anyone in.
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->authCollection('blog', 'blog'));
		$this->schemaFetcher->method('fetchRawSchema')->willReturn(new SchemaData());
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("accessCollection 'blog' is not an auth collection.");

		$this->saver->saveCollection(['id' => 'price-sheets', 'schema' => 'portfolio-schema', 'accessCollection' => 'blog']);
	}

	public function testPatchRejectsANonAuthOwnerCollection(): void
	{
		$this->repository->method('fetchCollection')->willReturn($this->storedCollection('portfolio-schema'));
		$this->collectionFetcher->method('fetchCollection')->willReturn($this->authCollection('blog', 'blog'));
		$this->schemaFetcher->method('fetchRawSchema')->willReturn(new SchemaData());
		$this->repository->expects($this->never())->method('saveCollection');

		$this->expectException(\DomainException::class);
		$this->expectExceptionMessage("ownerCollection 'blog' is not an auth collection.");

		$this->saver->patchCollection('portfolio', ['ownerCollection' => 'blog']);
	}

	public function testSaveWithEmptyOwnerCollectionSkipsTheCheck(): void
	{
		$this->collectionFetcher->expects($this->never())->method('fetchCollection');
		$this->repository->expects($this->once())->method('saveCollection');

		$this->saver->saveCollection(['id' => 'portfolio', 'schema' => 'portfolio-schema', 'ownerCollection' => '']);
	}

	public function testIncrementTotalObjects(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects incremented
				expect($collection->totalObjects)->toBe(6);

				// Verify lastUpdated was updated (should be recent)
				$updatedDate = new \DateTime($collection->lastUpdated);
				$now         = new \DateTime();
				$diff        = $now->getTimestamp() - $updatedDate->getTimestamp();
				expect($diff)->toBeLessThan(5); // Within 5 seconds

				return true;
			}));

		$result = $this->saver->incrementTotalObjects('test-collection');

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(6);
	}

	public function testDecrementTotalObjects(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects decremented
				expect($collection->totalObjects)->toBe(4);

				// Verify lastUpdated was updated
				expect($collection->lastUpdated)->not()->toBe('2025-01-01T00:00:00+00:00');

				return true;
			}));

		$result = $this->saver->decrementTotalObjects('test-collection');

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(4);
	}

	public function testDecrementTotalObjectsDoesNotGoBelowZero(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 0; // Already at zero

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Should remain at 0, not go negative
				expect($collection->totalObjects)->toBe(0);

				return true;
			}));

		$result = $this->saver->decrementTotalObjects('test-collection');

		expect($result->totalObjects)->toBe(0);
	}

	public function testUpdateLastUpdated(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects unchanged
				expect($collection->totalObjects)->toBe(5);

				// Verify lastUpdated was updated (should be now)
				$updatedDate = new \DateTime($collection->lastUpdated);
				$now         = new \DateTime();
				$diff        = $now->getTimestamp() - $updatedDate->getTimestamp();
				expect($diff)->toBeLessThan(5); // Within 5 seconds

				return true;
			}));

		$result = $this->saver->updateLastUpdated('test-collection');

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(5); // Should not change
	}

	public function testIncrementTotalObjectsThrowsExceptionForMissingCollection(): void
	{
		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('nonexistent')
			->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Error fetching Collection with id nonexistent');

		$this->saver->incrementTotalObjects('nonexistent');
	}

	public function testDecrementTotalObjectsThrowsExceptionForMissingCollection(): void
	{
		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('nonexistent')
			->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Error fetching Collection with id nonexistent');

		$this->saver->decrementTotalObjects('nonexistent');
	}

	public function testUpdateLastUpdatedThrowsExceptionForMissingCollection(): void
	{
		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('nonexistent')
			->willReturn(null);

		$this->expectException(\UnexpectedValueException::class);
		$this->expectExceptionMessage('Error fetching Collection with id nonexistent');

		$this->saver->updateLastUpdated('nonexistent');
	}

	public function testIncrementTotalObjectsHandlesMissingTotalObjects(): void
	{
		$collectionData         = new CollectionData();
		$collectionData->id     = 'test-collection';
		$collectionData->schema = 'blog';
		// totalObjects not set (defaults to 0)
		$collectionData->totalObjects = 0;

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				expect($collection->totalObjects)->toBe(1);

				return true;
			}));

		$result = $this->saver->incrementTotalObjects('test-collection');

		expect($result->totalObjects)->toBe(1);
	}

	public function testDecrementTotalObjectsHandlesMissingTotalObjects(): void
	{
		$collectionData         = new CollectionData();
		$collectionData->id     = 'test-collection';
		$collectionData->schema = 'blog';
		// totalObjects not set (defaults to 0)
		$collectionData->totalObjects = 0;

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Should remain at 0
				expect($collection->totalObjects)->toBe(0);

				return true;
			}));

		$result = $this->saver->decrementTotalObjects('test-collection');

		expect($result->totalObjects)->toBe(0);
	}

	public function testIncrementTotalObjectsWithIncrementBy(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->expects($this->once())
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects incremented by 10
				expect($collection->totalObjects)->toBe(15);

				return true;
			}));

		$result = $this->saver->incrementTotalObjects('test-collection', 10);

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(15);
	}

	public function testIncrementObjectCountsBumpsBothCountersInOneWrite(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->count        = 5;
		$collectionData->totalObjects = 3;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		$this->repository
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				expect($collection->count)->toBe(7)
					->and($collection->totalObjects)->toBe(5);

				return true;
			}));

		$result = $this->saver->incrementObjectCounts('test-collection', 2);

		expect($result->count)->toBe(7)
			->and($result->totalObjects)->toBe(5);
	}

	public function testIncrementCountWithIncrementBy(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->count        = 5;
		$collectionData->totalObjects = 5;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		// fetchCollection is called twice: once in incrementCount, once in updateCollection
		$this->repository
			->method('fetchCollection')
			->with('test-collection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify count incremented by 10
				expect($collection->count)->toBe(15);

				return true;
			}));

		$result = $this->saver->incrementCount('test-collection', 10);

		expect($result)->toBeInstanceOf(CollectionData::class);
	}

	public function testUpdateCollectionRecalculatesTotalObjectsWhenNotProvided(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->count        = 10;
		$collectionData->totalObjects = 5; // Old value
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		// The caller's snapshot is used for the update; the read under the
		// meta lock only keeps the lifetime count from going backwards.
		$this->repository
			->method('fetchCollection')
			->willReturn($collectionData);

		// Mock index repository to return 8 object IDs (the "real" count)
		$this->indexRepository
			->expects($this->atLeastOnce())
			->method('fetchObjectIds')
			->with('test-collection')
			->willReturn(['obj1', 'obj2', 'obj3', 'obj4', 'obj5', 'obj6', 'obj7', 'obj8']);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify totalObjects was recalculated from index (8 objects)
				expect($collection->totalObjects)->toBe(8);

				return true;
			}));

		// Update without providing totalObjects - should trigger recalculation
		$result = $this->saver->updateCollection('test-collection', [
			'id'     => 'test-collection',
			'schema' => 'blog',
			'name'   => 'Updated Name',
			// Note: totalObjects NOT provided
		], $collectionData);

		expect($result)->toBeInstanceOf(CollectionData::class);
		expect($result->totalObjects)->toBe(8);
	}

	public function testUpdateCollectionCorrectsCountWhenLessThanTotalObjects(): void
	{
		$collectionData               = new CollectionData();
		$collectionData->id           = 'test-collection';
		$collectionData->schema       = 'blog';
		$collectionData->count        = 3; // Less than totalObjects (incorrect)
		$collectionData->totalObjects = 10;
		$collectionData->lastUpdated  = '2025-01-01T00:00:00+00:00';

		// The caller's snapshot is used for the update; the read under the
		// meta lock only keeps the lifetime count from going backwards.
		$this->repository
			->method('fetchCollection')
			->willReturn($collectionData);

		$this->repository
			->expects($this->once())
			->method('saveCollection')
			->with($this->callback(function (CollectionData $collection): bool {
				// Verify count was corrected to match totalObjects
				expect($collection->count)->toBe(10);

				return true;
			}));

		// Update with totalObjects provided but count is wrong
		$result = $this->saver->updateCollection('test-collection', [
			'id'           => 'test-collection',
			'schema'       => 'blog',
			'count'        => 3, // Wrong value
			'totalObjects' => 10,
		], $collectionData);

		expect($result)->toBeInstanceOf(CollectionData::class);
	}
}
