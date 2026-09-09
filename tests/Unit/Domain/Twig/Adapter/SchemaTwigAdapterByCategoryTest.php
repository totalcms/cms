<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Service\CollectionEditionService;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\DeckCompatibilityChecker;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Twig\Adapter\SchemaTwigAdapter;
use TotalCMS\Factory\LoggerFactory;

/**
 * Reserved schemas with a non-empty `category` (e.g. "Internal") must land in
 * their own bucket from `SchemaTwigAdapter::byCategory()` instead of being
 * lumped into "Built-in Schemas" with everything else.
 */
final class SchemaTwigAdapterByCategoryTest extends TestCase
{
	private SchemaTwigAdapter $adapter;
	private MockObject $schemaLister;
	private MockObject $collectionEditionService;

	protected function setUp(): void
	{
		$this->schemaLister             = $this->createMock(SchemaLister::class);
		$schemaFetcher                  = $this->createMock(SchemaFetcher::class);
		$deckCompatibilityChecker       = $this->createMock(DeckCompatibilityChecker::class);
		$this->collectionEditionService = $this->createMock(CollectionEditionService::class);
		$loggerFactory                  = $this->createMock(LoggerFactory::class);

		$this->collectionEditionService->method('isSchemaAccessible')->willReturn(true);

		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->schemaLister->method('listExtensionSchemas')->willReturn([]);

		$this->adapter = new SchemaTwigAdapter(
			$this->schemaLister,
			$schemaFetcher,
			$deckCompatibilityChecker,
			$this->collectionEditionService,
			$loggerFactory,
		);
	}

	private function reservedSchema(string $id, string $category = ''): SchemaData
	{
		$schema           = new SchemaData();
		$schema->id       = $id;
		$schema->category = $category;

		return $schema;
	}

	public function testByCategoryGroupsInternalReservedSchemasSeparately(): void
	{
		$this->schemaLister->method('listReservedSchemas')->willReturn([
			$this->reservedSchema('blog'),
			$this->reservedSchema('sitemap-meta', 'Internal'),
		]);

		$result = $this->adapter->byCategory();

		$this->assertSame(['Built-in Schemas', 'Internal'], array_keys($result));
		$this->assertSame('blog', $result['Built-in Schemas'][0]['id']);
		$this->assertSame('sitemap-meta', $result['Internal'][0]['id']);
	}

	public function testByCategoryOmitsInternalBucketWhenNoReservedSchemaUsesIt(): void
	{
		$this->schemaLister->method('listReservedSchemas')->willReturn([
			$this->reservedSchema('blog'),
			$this->reservedSchema('image'),
		]);

		$result = $this->adapter->byCategory();

		$this->assertSame(['Built-in Schemas'], array_keys($result));
		$this->assertSame(['blog', 'image'], array_column($result['Built-in Schemas'], 'id'));
	}
}
