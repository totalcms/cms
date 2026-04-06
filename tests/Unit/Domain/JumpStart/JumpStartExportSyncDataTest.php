<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\JumpStart;

use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionLister;
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
	private \PHPUnit\Framework\MockObject\MockObject $schemaLister;
	private \PHPUnit\Framework\MockObject\MockObject $templateLister;
	private \PHPUnit\Framework\MockObject\MockObject $templateFetcher;

	protected function setUp(): void
	{
		$collectionLister = $this->createMock(CollectionLister::class);
		$this->schemaLister    = $this->createMock(SchemaLister::class);
		$schemaFetcher    = $this->createMock(SchemaFetcher::class);
		$objectFetcher    = $this->createMock(ObjectFetcher::class);
		$indexReader      = $this->createMock(IndexReader::class);
		$this->templateLister  = $this->createMock(TemplateLister::class);
		$this->templateFetcher = $this->createMock(TemplateFetcher::class);
		$cacheManager     = $this->createMock(CacheManager::class);
		$loggerFactory    = $this->createMock(LoggerFactory::class);

		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn($this->createMock(\Psr\Log\LoggerInterface::class));

		$this->exporter = new JumpStartExporter(
			$collectionLister,
			$this->schemaLister,
			$schemaFetcher,
			$objectFetcher,
			$indexReader,
			$this->templateLister,
			$this->templateFetcher,
			new JumpStartData(),
			$cacheManager,
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

		$this->templateLister->method('listCustomTemplates')->willReturn(['blog-post']);
		$this->templateFetcher->method('fetchTemplate')->with('blog-post')->willReturn($template1);

		$result = $this->exporter->exportSyncData();

		expect($result)->toBeInstanceOf(JumpStartData::class);
		expect($result->schemas)->toHaveCount(2);
		expect($result->templates)->toHaveCount(1);
		expect($result->objects)->toHaveCount(0);
		expect($result->collections)->toBe(['reserved' => [], 'custom' => []]);
	}

	public function testExportSyncDataDoesNotExportCollectionsOrObjects(): void
	{
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
		$this->templateLister->method('listCustomTemplates')->willReturn([]);

		$result = $this->exporter->exportSyncData();

		expect($result->objects)->toHaveCount(0);
		expect($result->collections)->toBe(['reserved' => [], 'custom' => []]);
		expect($result->factory)->toHaveCount(0);
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
		$this->templateLister->method('listCustomTemplates')->willReturn([]);

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

		$this->templateLister->method('listCustomTemplates')->willReturn(['blog-post', 'blog-list', 'sidebar']);
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

		$this->templateLister->method('listCustomTemplates')->willReturn(['blog-post', 'sidebar']);
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
		$this->templateLister->method('listCustomTemplates')->willReturn([]);

		$result = $this->exporter->exportSyncData([], []);

		expect($result->schemas)->toHaveCount(0);
		expect($result->templates)->toHaveCount(0);
	}
}
