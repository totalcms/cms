<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JumpStart;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TotalCMS\Domain\Builder\Repository\BuilderOrderRepository;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\JumpStart\Data\JumpStartData;
use TotalCMS\Domain\JumpStart\Data\JumpStartExportOptions;
use TotalCMS\Domain\JumpStart\Service\JumpStartExporter;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Factory\LoggerFactory;

/**
 * A collection built on a reserved schema is not itself a reserved collection
 * unless its id is the schema's id. `dealers` (schema auth) and
 * `builder-pages` (schema builder-page) used to export as the bare reserved
 * strings "auth" and "builder-page": the import then created the wrong
 * collection, `dealers` never existed, and every builder-pages object failed
 * with "Collection not found".
 */
final class JumpStartExportReservedSchemaCollectionsTest extends TestCase
{
	/** @param list<CollectionData> $collections */
	private function makeExporter(array $collections): JumpStartExporter
	{
		$collectionLister = $this->createMock(CollectionLister::class);
		$collectionLister->method('listAllCollections')->willReturn($collections);

		$indexReader = $this->createMock(IndexReader::class);
		$indexReader->method('fetchIndex')->willReturn(new IndexData([]));

		$schemaLister = $this->createMock(SchemaLister::class);
		$schemaLister->method('listCustomSchemas')->willReturn([]);

		$templateLister = $this->createMock(TemplateLister::class);
		$templateLister->method('listBuilderTemplates')->willReturn([]);

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->createMock(LoggerInterface::class));

		return new JumpStartExporter(
			$collectionLister,
			$schemaLister,
			$this->createMock(SchemaFetcher::class),
			$this->createMock(ObjectFetcher::class),
			$indexReader,
			$templateLister,
			$this->createMock(TemplateFetcher::class),
			new JumpStartData(),
			$this->createMock(CacheManager::class),
			$this->createMock(BuilderOrderRepository::class),
			$loggerFactory,
		);
	}

	private function collection(string $id, string $schema, string $name = ''): CollectionData
	{
		$collection         = new CollectionData();
		$collection->id     = $id;
		$collection->schema = $schema;
		$collection->name   = $name;

		return $collection;
	}

	/**
	 * @param array{custom: list<array<string,mixed>>, reserved: list<string|array<string,mixed>>} $collections
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function customById(array $collections): array
	{
		return array_column($collections['custom'], null, 'id');
	}

	public function testCollectionsOnReservedSchemasExportAsCustomWithTheirOwnId(): void
	{
		$data = $this->makeExporter([
			$this->collection('blog', 'blog'),
			$this->collection('dealers', 'auth', 'Dealers'),
			$this->collection('builder-pages', 'builder-page'),
		])->exportCurrentData(new JumpStartExportOptions(
			schemaFilter: [],
			objectFilter: [],
			templateFilter: [],
		));

		expect(array_column($data->collections['reserved'], 'id'))->toBe(['blog']);

		$custom = $this->customById($data->collections);
		expect(array_keys($custom))->toBe(['dealers', 'builder-pages']);
		expect($custom['dealers']['schema'])->toBe('auth');
		expect($custom['dealers']['name'])->toBe('Dealers');
		expect($custom['builder-pages']['schema'])->toBe('builder-page');
	}

	public function testReservedCollectionsCarryTheirOwnSettings(): void
	{
		// A reserved collection used to export as its bare id, so the import
		// rebuilt it with defaults and its url, sort and cards were lost.
		$blog              = $this->collection('blog', 'blog', 'Journal');
		$blog->url         = '/journal/{id}';
		$blog->sortBy      = 'date';
		$blog->reverseSort = true;
		$blog->feed        = ['enabled' => true];
		$blog->count       = 42;
		$blog->format      = CollectionData::FORMAT_MARKDOWN;

		$data = $this->makeExporter([$blog])->exportCurrentData(new JumpStartExportOptions(
			schemaFilter: [],
			objectFilter: [],
			templateFilter: [],
		));

		$entry = $data->collections['reserved'][0];
		expect($entry)->toBeArray();
		expect($entry['id'])->toBe('blog');
		expect($entry['name'])->toBe('Journal');
		expect($entry['url'])->toBe('/journal/{id}');
		expect($entry['sortBy'])->toBe('date');
		expect($entry['reverseSort'])->toBeTrue();
		expect($entry['feed'])->toBe(['enabled' => true]);

		// Computed counters are the target's own, and the importer patches
		// settings raw, so format would relabel files without converting them.
		expect($entry)->not->toHaveKey('count');
		expect($entry)->not->toHaveKey('totalObjects');
		expect($entry)->not->toHaveKey('lastUpdated');
		expect($entry)->not->toHaveKey('format');
	}

	public function testSyncExportMakesTheSameChoice(): void
	{
		$data = $this->makeExporter([
			$this->collection('blog', 'blog'),
			$this->collection('builder-pages', 'builder-page'),
		])->exportSyncData([], [], [], null);

		expect(array_column($data->collections['reserved'], 'id'))->toBe(['blog']);
		expect(array_keys($this->customById($data->collections)))->toBe(['builder-pages']);
		expect($this->customById($data->collections)['builder-pages']['schema'])->toBe('builder-page');
	}
}
