<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JumpStart;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Factory\LoggerFactory;

final class JumpStartExportStripsSensitiveFieldsTest extends TestCase
{
	private function makeExporter(SchemaFetcher $schemaFetcher, ObjectFetcher $objectFetcher, CollectionLister $collectionLister, IndexReader $indexReader): JumpStartExporter
	{
		$schemaLister    = $this->createMock(SchemaLister::class);
		$templateLister  = $this->createMock(TemplateLister::class);
		$templateFetcher = $this->createMock(TemplateFetcher::class);
		$cacheManager    = $this->createMock(CacheManager::class);
		$loggerFactory   = $this->createMock(LoggerFactory::class);

		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(
			$this->createMock(\Psr\Log\LoggerInterface::class)
		);

		$schemaLister->method('listCustomSchemas')->willReturn([]);
		$templateLister->method('listBuilderTemplates')->willReturn([]);

		return new JumpStartExporter(
			$collectionLister,
			$schemaLister,
			$schemaFetcher,
			$objectFetcher,
			$indexReader,
			$templateLister,
			$templateFetcher,
			new JumpStartData(),
			$cacheManager,
			$this->createMock(BuilderOrderRepository::class),
			$loggerFactory,
		);
	}

	public function testPasswordFieldIsStrippedFromExport(): void
	{
		$collection         = new CollectionData();
		$collection->id     = 'members';
		$collection->schema = 'auth';

		$collectionLister = $this->createMock(CollectionLister::class);
		$collectionLister->method('listAllCollections')->willReturn([$collection]);

		$indexReader = $this->createMock(IndexReader::class);
		$indexReader->method('fetchIndex')->with('members')->willReturn(
			new IndexData([['id' => 'user-1']])
		);

		// Object has email (safe) + password (sensitive) + a secret (sensitive)
		$objectData     = $this->createMock(ObjectData::class);
		$objectData->id = 'user-1';
		$objectData->method('toArray')->willReturn([
			'id'       => 'user-1',
			'email'    => 'alice@example.com',
			'password' => '$2y$12$hashedvalue',
			'apikey'   => 's3cr3t-token',
			'name'     => 'Alice',
		]);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('fetchObject')->with('members', 'user-1')->willReturn($objectData);

		// Schema: email=email field, password=password field, apikey=secret field, name=text field
		$schema             = new SchemaData();
		$schema->id         = 'auth';
		$schema->properties = [
			'email'    => ['field' => 'email'],
			'password' => ['field' => 'password'],
			'apikey'   => ['field' => 'secret'],
			'name'     => ['field' => 'text'],
		];

		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchema')->with('auth')->willReturn($schema);

		$exporter = $this->makeExporter($schemaFetcher, $objectFetcher, $collectionLister, $indexReader);
		$result   = $exporter->exportCurrentData();

		expect($result->objects)->toHaveCount(1);
		$exported = $result->objects[0]['data'];

		// Sensitive fields must be absent
		expect($exported)->not()->toHaveKey('password');
		expect($exported)->not()->toHaveKey('apikey');

		// Non-sensitive fields must be present and correct
		expect($exported)->toHaveKey('email');
		expect($exported['email'])->toBe('alice@example.com');
		expect($exported)->toHaveKey('name');
		expect($exported['name'])->toBe('Alice');

		// id is always stripped by processObjectData
		expect($exported)->not()->toHaveKey('id');
	}

	public function testSensitiveFieldAbsentInObjectIsHandledSafely(): void
	{
		// Field defined in schema but not present in object data — no error
		$collection         = new CollectionData();
		$collection->id     = 'members';
		$collection->schema = 'auth';

		$collectionLister = $this->createMock(CollectionLister::class);
		$collectionLister->method('listAllCollections')->willReturn([$collection]);

		$indexReader = $this->createMock(IndexReader::class);
		$indexReader->method('fetchIndex')->with('members')->willReturn(
			new IndexData([['id' => 'user-2']])
		);

		$objectData     = $this->createMock(ObjectData::class);
		$objectData->id = 'user-2';
		$objectData->method('toArray')->willReturn([
			'id'   => 'user-2',
			'name' => 'Bob',
			// password field absent — object may not have been set yet
		]);

		$objectFetcher = $this->createMock(ObjectFetcher::class);
		$objectFetcher->method('fetchObject')->willReturn($objectData);

		$schema             = new SchemaData();
		$schema->id         = 'auth';
		$schema->properties = [
			'password' => ['field' => 'password'],
			'name'     => ['field' => 'text'],
		];

		$schemaFetcher = $this->createMock(SchemaFetcher::class);
		$schemaFetcher->method('fetchSchema')->willReturn($schema);

		$exporter = $this->makeExporter($schemaFetcher, $objectFetcher, $collectionLister, $indexReader);
		$result   = $exporter->exportCurrentData();

		expect($result->objects)->toHaveCount(1);
		$exported = $result->objects[0]['data'];

		expect($exported)->not()->toHaveKey('password');
		expect($exported)->toHaveKey('name');
		expect($exported['name'])->toBe('Bob');
	}
}
