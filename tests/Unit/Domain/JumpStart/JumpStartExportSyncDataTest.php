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
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Domain\Schema\Data\SchemaData;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Template\Data\TemplateData;
use TotalCMS\Domain\Template\Service\TemplateFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;
use TotalCMS\Factory\LoggerFactory;

final class JumpStartExportSyncDataTest extends TestCase
{
	private JumpStartExporter $exporter;
	private \PHPUnit\Framework\MockObject\MockObject $collectionLister;
	private \PHPUnit\Framework\MockObject\MockObject $schemaLister;
	private \PHPUnit\Framework\MockObject\MockObject $templateLister;
	private \PHPUnit\Framework\MockObject\MockObject $templateFetcher;
	private \PHPUnit\Framework\MockObject\MockObject $indexReader;
	private \PHPUnit\Framework\MockObject\MockObject $objectFetcher;

	protected function setUp(): void
	{
		$this->collectionLister = $this->createMock(CollectionLister::class);
		$this->schemaLister     = $this->createMock(SchemaLister::class);
		$schemaFetcher          = $this->createMock(SchemaFetcher::class);
		$this->objectFetcher    = $this->createMock(ObjectFetcher::class);
		$this->indexReader      = $this->createMock(IndexReader::class);
		$this->templateLister   = $this->createMock(TemplateLister::class);
		$this->templateFetcher  = $this->createMock(TemplateFetcher::class);
		$cacheManager           = $this->createMock(CacheManager::class);
		$loggerFactory          = $this->createMock(LoggerFactory::class);

		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

		$this->exporter = new JumpStartExporter(
			$this->collectionLister,
			$this->schemaLister,
			$schemaFetcher,
			$this->objectFetcher,
			$this->indexReader,
			$this->templateLister,
			$this->templateFetcher,
			new JumpStartData(),
			$cacheManager,
			$this->createMock(BuilderOrderRepository::class),
			$loggerFactory
		);
	}

	public function testExportSyncDataExportsAllSchemasAndTemplates(): void
	{
		$schema1     = new SchemaData();
		$schema1->id = 'blog-custom';
		$schema2     = new SchemaData();
		$schema2->id = 'products';

		$this->schemaLister->method('listCustomSchemas')->willReturn([$schema1, $schema2]);

		$template1           = new TemplateData();
		$template1->id       = 'blog-post';
		$template1->contents = '<h1>Blog</h1>';

		$this->templateLister->method('listBuilderTemplates')->willReturn(['blog-post']);
		$this->templateFetcher->method('fetchTemplate')->with('blog-post')->willReturn($template1);
		$this->collectionLister->method('listAllCollections')->willReturn([]);

		$result = $this->exporter->exportSyncData();

		expect($result)->toBeInstanceOf(JumpStartData::class);
		expect($result->schemas)->toHaveCount(2);
		expect($result->templates)->toHaveCount(1);
		expect($result->objects)->toHaveCount(0);
		expect($result->collections)->toBe(['reserved' => [], 'custom' => []]);
	}

	public function testExportSyncDataDoesNotExportCustomCollectionsOrFactories(): void
	{
		// The legacy guarantee — sync mode never touches custom collections or
		// the factory pipeline. Reserved-allowlist objects can appear via the
		// sync-collections feature; that's covered separately below.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);
		$this->collectionLister->method('listAllCollections')->willReturn([]);

		$result = $this->exporter->exportSyncData();

		expect($result->objects)->toHaveCount(0);
		expect($result->collections)->toBe(['reserved' => [], 'custom' => []]);
		expect($result->factory)->toHaveCount(0);
	}

	public function testExportSyncDataIteratesAllowlistedCollections(): void
	{
		// When an allowlist collection exists locally, exportSyncData must call
		// fetchIndex against it. The end-to-end object marshalling pipeline
		// (which depends on full SchemaData + Property object wiring) is
		// exercised in SyncServiceTest with the exporter mocked — here we just
		// verify the allowlist drives index lookups.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$builderPages         = new CollectionData();
		$builderPages->id     = 'builder-pages';
		$builderPages->schema = 'builder-page';
		$this->collectionLister->method('listAllCollections')->willReturn([$builderPages]);

		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with('builder-pages')
			->willReturn(new IndexData([]));

		$this->exporter->exportSyncData();
	}

	public function testExportSyncDataCollectionFilterIsAllowlistConstrained(): void
	{
		// Defense in depth: even if a malformed filter slips in a non-allowlist
		// key (here 'image' — which carries files), it MUST be ignored. Only
		// keys present in SyncableCollections::IDS are iterated.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);
		$this->collectionLister->method('listAllCollections')->willReturn([]);
		$this->indexReader->expects($this->never())->method('fetchIndex');

		$result = $this->exporter->exportSyncData(null, null, ['image' => null]);

		expect($result->objects)->toHaveCount(0);
	}

	public function testExportSyncDataEmptyCollectionsMapSkipsAllCollections(): void
	{
		// An empty map (every section set to "none" in the UI) means
		// "no collections at all" — distinct from null (which means
		// "all of them" for back-compat). With collection META also excluded,
		// nothing collection-shaped is touched: no listing, no index reads.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);
		$this->collectionLister->expects($this->never())->method('listAllCollections');
		$this->indexReader->expects($this->never())->method('fetchIndex');

		$result = $this->exporter->exportSyncData(null, null, [], []);

		expect($result->objects)->toHaveCount(0);
	}

	public function testExportSyncDataFiltersSchemas(): void
	{
		$schema1     = new SchemaData();
		$schema1->id = 'blog-custom';
		$schema2     = new SchemaData();
		$schema2->id = 'products';
		$schema3     = new SchemaData();
		$schema3->id = 'invoice';

		$this->schemaLister->method('listCustomSchemas')->willReturn([$schema1, $schema2, $schema3]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$result = $this->exporter->exportSyncData(['products', 'invoice']);

		expect($result->schemas)->toHaveCount(2);
		expect($result->schemas[0]['id'])->toBe('products');
		expect($result->schemas[1]['id'])->toBe('invoice');
	}

	public function testExportSyncDataFiltersTemplates(): void
	{
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);

		$template1           = new TemplateData();
		$template1->id       = 'blog-post';
		$template1->contents = '<h1>Blog</h1>';

		$template2           = new TemplateData();
		$template2->id       = 'blog-list';
		$template2->contents = '<ul>List</ul>';

		$template3           = new TemplateData();
		$template3->id       = 'sidebar';
		$template3->contents = '<aside>Side</aside>';

		$this->templateLister->method('listBuilderTemplates')->willReturn(['blog-post', 'blog-list', 'sidebar']);
		$this->templateFetcher->method('fetchTemplate')->willReturnCallback(
			fn (string $path): TemplateData => match ($path) {
				'blog-post' => $template1,
				'blog-list' => $template2,
				'sidebar'   => $template3,
			}
		);

		$result = $this->exporter->exportSyncData(null, ['blog-post', 'sidebar']);

		expect($result->templates)->toHaveCount(2);
		expect($result->templates[0]['id'])->toBe('blog-post');
		expect($result->templates[1]['id'])->toBe('sidebar');
	}

	public function testExportSyncDataFiltersBothSchemasAndTemplates(): void
	{
		$schema1     = new SchemaData();
		$schema1->id = 'products';
		$schema2     = new SchemaData();
		$schema2->id = 'invoice';

		$this->schemaLister->method('listCustomSchemas')->willReturn([$schema1, $schema2]);

		$template1           = new TemplateData();
		$template1->id       = 'blog-post';
		$template1->contents = '<h1>Blog</h1>';

		$template2           = new TemplateData();
		$template2->id       = 'sidebar';
		$template2->contents = '<aside>Side</aside>';

		$this->templateLister->method('listBuilderTemplates')->willReturn(['blog-post', 'sidebar']);
		$this->templateFetcher->method('fetchTemplate')->willReturnCallback(
			fn (string $path): TemplateData => match ($path) {
				'blog-post' => $template1,
				'sidebar'   => $template2,
			}
		);

		$result = $this->exporter->exportSyncData(['products'], ['sidebar']);

		expect($result->schemas)->toHaveCount(1);
		expect($result->schemas[0]['id'])->toBe('products');
		expect($result->templates)->toHaveCount(1);
		expect($result->templates[0]['id'])->toBe('sidebar');
	}

	public function testExportSyncDataWithEmptyFilterReturnsNothing(): void
	{
		$schema     = new SchemaData();
		$schema->id = 'products';

		$this->schemaLister->method('listCustomSchemas')->willReturn([$schema]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$result = $this->exporter->exportSyncData([], []);

		expect($result->schemas)->toHaveCount(0);
		expect($result->templates)->toHaveCount(0);
	}

	public function testExportSyncDataExcludesPlaygroundCollectionMeta(): void
	{
		// The Twig Playground creates its collection on demand, on whichever
		// install someone happens to open the tool on. Mirroring that container
		// reports a permanent "only on production" the operator can neither act
		// on (the UI lists local collections only) nor would ever want to fix.
		// Its objects are already excluded — the settings must match.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$playground         = new CollectionData();
		$playground->id     = 'playground';
		$playground->schema = 'playground';

		$blog         = new CollectionData();
		$blog->id     = 'blog';
		$blog->schema = 'blog';

		$this->collectionLister->method('listAllCollections')->willReturn([$playground, $blog]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([]));

		$result = $this->exporter->exportSyncData();

		$exported = array_column($result->collections['reserved'], 'id');
		expect($exported)->toContain('blog');
		expect($exported)->not->toContain('playground');
	}

	public function testExportSyncDataExcludesPlaygroundEvenWhenExplicitlyFiltered(): void
	{
		// Defense in depth: a stale or hand-rolled filter naming 'playground'
		// must not smuggle it back into the mirror.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$playground         = new CollectionData();
		$playground->id     = 'playground';
		$playground->schema = 'playground';

		$this->collectionLister->method('listAllCollections')->willReturn([$playground]);
		$this->indexReader->method('fetchIndex')->willReturn(new IndexData([]));

		$result = $this->exporter->exportSyncData(null, null, null, ['playground']);

		expect($result->collections)->toBe(['reserved' => [], 'custom' => []]);
	}

	public function testSeedFilterReachesCollectionsOutsideTheAllowlist(): void
	{
		// The whole point of --objects: `blog` is not in SyncableCollections::IDS,
		// so the mirror path ignores it. The seed path must not.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);

		$blog         = new CollectionData();
		$blog->id     = 'blog';
		$blog->schema = 'blog';
		$this->collectionLister->method('listAllCollections')->willReturn([$blog]);

		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with('blog')
			->willReturn(new IndexData([]));

		$this->exporter->exportSyncData(null, null, [], [], ['blog' => null]);
	}

	public function testSeedFilterStillRefusesCarvedOutCollections(): void
	{
		// Defense in depth on the seed path: `image` is binary-only, so even an
		// explicit request must not reach it — the binary IS the object there.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);
		$this->collectionLister->method('listAllCollections')->willReturn([]);
		$this->indexReader->expects($this->never())->method('fetchIndex');

		$result = $this->exporter->exportSyncData(null, null, [], [], ['image' => null]);

		expect($result->objects)->toHaveCount(0);
	}

	public function testSeedFilterRefusesCollectionsOwnedByAFeatureFlag(): void
	{
		// builder-pages is reachable, but through --pages. One way to move a
		// thing is enough, so the seed path must refuse it.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);
		$this->collectionLister->method('listAllCollections')->willReturn([]);
		$this->indexReader->expects($this->never())->method('fetchIndex');

		$result = $this->exporter->exportSyncData(null, null, [], [], ['builder-pages' => null]);

		expect($result->objects)->toHaveCount(0);
	}

	public function testMirrorPathStillIgnoresNonAllowlistedCollections(): void
	{
		// Regression guard on this task's refactor: extracting the shared loop
		// must not loosen the mirror path's allowlist.
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listBuilderTemplates')->willReturn([]);
		$this->collectionLister->method('listAllCollections')->willReturn([]);
		$this->indexReader->expects($this->never())->method('fetchIndex');

		$result = $this->exporter->exportSyncData(null, null, ['blog' => null], []);

		expect($result->objects)->toHaveCount(0);
	}
}
