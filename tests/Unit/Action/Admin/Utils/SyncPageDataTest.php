<?php

declare(strict_types=1);

namespace Tests\Unit\Action\Admin\Utils;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Admin\Utils\SyncPageData;
use TotalCMS\Domain\Builder\Service\BuilderTemplatePaths;
use TotalCMS\Domain\Collection\Data\CollectionData;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Playground\Data\PlaygroundData;
use TotalCMS\Domain\Schema\Service\SchemaLister;
use TotalCMS\Domain\Settings\Services\SettingsFetcher;
use TotalCMS\Domain\Template\Service\TemplateLister;

final class SyncPageDataTest extends TestCase
{
	private MockObject $templatePaths;
	private MockObject $collectionLister;
	private MockObject $collectionFetcher;
	private MockObject $indexReader;
	private MockObject $settingsFetcher;
	private MockObject $schemaLister;
	private MockObject $templateLister;
	private SyncPageData $builder;

	protected function setUp(): void
	{
		$this->templatePaths     = $this->createMock(BuilderTemplatePaths::class);
		$this->collectionLister  = $this->createMock(CollectionLister::class);
		$this->collectionFetcher = $this->createMock(CollectionFetcher::class);
		$this->indexReader       = $this->createMock(IndexReader::class);
		$this->settingsFetcher   = $this->createMock(SettingsFetcher::class);
		$this->schemaLister      = $this->createMock(SchemaLister::class);
		$this->templateLister    = $this->createMock(TemplateLister::class);
		$this->builder           = new SyncPageData(
			$this->templatePaths,
			$this->collectionLister,
			$this->collectionFetcher,
			$this->indexReader,
			$this->settingsFetcher,
			$this->schemaLister,
			$this->templateLister,
		);
		$this->settingsFetcher->method('loadSection')->willReturn([]);
		$this->schemaLister->method('listCustomSchemas')->willReturn([]);
	}

	private function build(): array
	{
		return $this->builder->build($this->createMock(ServerRequestInterface::class), 'sync', '')['syncData'];
	}

	// CollectionData's constructor takes no arguments (see OAuthPageDataTest),
	// so fixtures are built via property assignment rather than the brief's
	// `new CollectionData(['id' => ...])` shorthand.
	private function collection(string $id, string $name): CollectionData
	{
		$collection       = new CollectionData();
		$collection->id   = $id;
		$collection->name = $name;

		return $collection;
	}

	public function testCollectionMetaSkipsThePlaygroundAndSortsByName(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([
			$this->collection('zeta', 'Zeta'),
			$this->collection(PlaygroundData::COLLECTION_ID, 'Playground'),
			$this->collection('alpha', ''),
		]);
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);

		$meta = $this->build()['collectionMeta'];

		$this->assertSame([['id' => 'alpha', 'name' => 'Alpha'], ['id' => 'zeta', 'name' => 'Zeta']], $meta);
	}

	public function testGitManagedTemplatesHideTheTemplatePicker(): void
	{
		$this->templatePaths->method('isProjectManaged')->willReturn(true);
		$this->collectionLister->method('listAllCollections')->willReturn([]);
		$this->collectionFetcher->method('fetchCollection')->willReturn(null);
		$this->templateLister->expects($this->never())->method('listBuilderTemplates');

		$data = $this->build();

		$this->assertTrue($data['templatesGitManaged']);
		$this->assertSame([], $data['templates']);
	}

	public function testSyncableCollectionsCarryLabelledObjectsFromTheIndex(): void
	{
		$this->collectionLister->method('listAllCollections')->willReturn([]);
		$pages = $this->collection('builder-pages', 'Pages');
		$this->collectionFetcher->method('fetchCollection')->willReturnCallback(
			static fn (string $id): ?CollectionData => $id === 'builder-pages' ? $pages : null,
		);
		// IndexData::$objects is typed as an Illuminate Collection (built by
		// the constructor via collect()), not a plain array, and the
		// property is not readonly — but assigning a raw array to it via
		// ReflectionClass::newInstanceWithoutConstructor() would still throw
		// a TypeError, so we go through the real constructor instead.
		$index = new IndexData([
			['id' => 'home', 'title' => 'Home', 'route' => '/'],
			['id' => 'about', 'title' => 'About'],
			['id' => '', 'title' => 'ignored'],
		]);
		$this->indexReader->method('fetchIndex')->willReturnCallback(
			static fn (string $id) => $id === 'builder-pages' ? $index : throw new \RuntimeException('no index'),
		);

		$collections = $this->build()['collections'];

		$this->assertCount(1, $collections);
		$this->assertSame('builder-pages', $collections[0]['id']);
		$this->assertSame('Pages', $collections[0]['name']);
		$this->assertSame([['id' => 'home', 'label' => '/'], ['id' => 'about', 'label' => 'About']], $collections[0]['objects']);
	}
}
