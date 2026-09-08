<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\ImportPageData;
use TotalCMS\Domain\Builder\Service\BuilderInstaller;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Import\RssImporter;
use TotalCMS\Domain\Schema\Data\SchemaData;

final class ImportPageDataTest extends TestCase
{
	private MockObject $collectionFetcher;
	private MockObject $collectionLister;
	private MockObject $builderInstaller;
	private MockObject $rssImporter;
	private MockObject $request;
	private ImportPageData $builder;

	protected function setUp(): void
	{
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->collectionLister  = $this->createMock(CollectionLister::class);
		$this->builderInstaller  = $this->createMock(BuilderInstaller::class);
		$this->rssImporter       = $this->createMock(RssImporter::class);
		$this->request           = $this->createMock(ServerRequestInterface::class);
		$this->builder           = new ImportPageData(
			$this->collectionFetcher,
			$this->collectionLister,
			$this->builderInstaller,
			$this->rssImporter,
		);
	}

	public function testProjectSetupReturnsDetectionDataKeyEvenWhenNothingIsFound(): void
	{
		$this->request->method('getMethod')->willReturn('GET');
		$data = $this->builder->build($this->request, 'project-setup', '');

		$this->assertArrayHasKey('totalcms1DetectionData', $data);
		$this->assertNull($data['rssAnalysis']);
		$this->assertNull($data['rssError']);
		$this->assertNull($data['rssCollections']);
	}

	public function testDefaultCollectionsActionCreatesEveryDefaultCollectionAndThePagesCollection(): void
	{
		$this->request->method('getMethod')->willReturn('GET');
		$this->collectionFetcher->expects($this->exactly(count(SchemaData::DEFAULT_COLLECTIONS)))
			->method('fetchOrCreateReserved');
		$this->builderInstaller->expects($this->once())->method('ensurePagesCollection');

		$this->builder->build($this->request, 'project-setup', 'default-collections');
	}

	public function testProjectSetupWithoutTheActionDoesNotCreateCollections(): void
	{
		$this->request->method('getMethod')->willReturn('GET');
		$this->collectionFetcher->expects($this->never())->method('fetchOrCreateReserved');
		$this->builderInstaller->expects($this->never())->method('ensurePagesCollection');

		$this->builder->build($this->request, 'project-setup', '');
	}

	public function testImportRssPostAnalyzesTheFeedAndListsCollections(): void
	{
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn(['url' => ' https://example.com/feed.xml ']);
		$this->rssImporter->expects($this->once())->method('analyze')
			->with('https://example.com/feed.xml')
			->willReturn(['title' => 'Feed']);
		$this->collectionLister->method('listAllCollections')->willReturn([]);

		$data = $this->builder->build($this->request, 'import-rss', '');

		$this->assertSame(['title' => 'Feed'], $data['rssAnalysis']);
		$this->assertNull($data['rssError']);
		$this->assertSame([], $data['rssCollections']);
		$this->assertNull($data['totalcms1DetectionData']);
	}

	public function testImportRssPostReportsAnalyzerErrors(): void
	{
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn(['url' => 'https://example.com/feed.xml']);
		$this->rssImporter->method('analyze')->willThrowException(new \RuntimeException('boom'));

		$data = $this->builder->build($this->request, 'import-rss', '');

		$this->assertNull($data['rssAnalysis']);
		$this->assertSame('boom', $data['rssError']);
		$this->assertNull($data['rssCollections']);
	}

	public function testImportRssPostWithAnEmptyUrlDoesNotAnalyze(): void
	{
		$this->request->method('getMethod')->willReturn('POST');
		$this->request->method('getParsedBody')->willReturn(['url' => '  ']);
		$this->rssImporter->expects($this->never())->method('analyze');

		$data = $this->builder->build($this->request, 'import-rss', '');

		$this->assertNull($data['rssAnalysis']);
	}
}
