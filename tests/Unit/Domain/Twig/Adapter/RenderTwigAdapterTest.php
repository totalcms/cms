<?php

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Cache\CacheManager;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\Collection\Service\ObjectUrlBuilder;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class RenderTwigAdapterTest extends TestCase
{
	private MockObject&HtmxRenderer $htmxRenderer;
	private RenderTwigAdapter $adapter;

	protected function setUp(): void
	{
		$this->htmxRenderer = $this->createMock(HtmxRenderer::class);

		$config      = $this->createMock(Config::class);
		$config->api = '/api';

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new \Psr\Log\NullLogger());

		$this->adapter = new RenderTwigAdapter(
			$this->htmxRenderer,
			$config,
			$this->createMock(DataTwigAdapter::class),
			$this->createMock(MediaTwigAdapter::class),
			$this->createMock(CollectionFetcher::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(SchemaFetcher::class),
			$this->createMock(IndexReader::class),
			$this->createMock(ObjectUrlBuilder::class),
			$this->createMock(CacheManager::class),
			$this->createMock(GridRenderer::class),
			$loggerFactory,
		);
	}

	// --- loadMore ---

	public function testLoadMoreReturnsErrorWhenTemplateMissing(): void
	{
		$result = $this->adapter->loadMore('blog');

		$this->assertStringContainsString('template', $result);
		$this->assertStringContainsString('<!--', $result);
	}

	public function testLoadMoreReturnsErrorWhenTemplateEmpty(): void
	{
		$result = $this->adapter->loadMore('blog', ['template' => '']);

		$this->assertStringContainsString('template', $result);
	}

	public function testLoadMoreBuildsCorrectBaseUrl(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				'/api/collections/blog/query',
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn('<div>trigger</div>');

		$this->adapter->loadMore('blog', ['template' => 'blog/card']);
	}

	public function testLoadMorePassesOptionsToRenderer(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				$this->anything(),
				$this->callback(fn (array $params): bool => $params['format'] === 'html'
						&& $params['template'] === 'blog/card'
						&& $params['limit'] === '10'
						&& $params['offset'] === '10'),
				'revealed',
				'Load More',
				'',
				false,
			)
			->willReturn('<div>trigger</div>');

		$this->adapter->loadMore('blog', [
			'template' => 'blog/card',
			'limit'    => 10,
		]);
	}

	public function testLoadMoreForwardsOptionalParams(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				$this->anything(),
				$this->callback(fn (array $params): bool => $params['sort'] === 'date:desc'
						&& $params['include'] === 'published:true'
						&& $params['exclude'] === 'draft:true'
						&& $params['search'] === 'hello'),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn('<div>trigger</div>');

		$this->adapter->loadMore('blog', [
			'template' => 'blog/card',
			'sort'     => 'date:desc',
			'include'  => 'published:true',
			'exclude'  => 'draft:true',
			'search'   => 'hello',
		]);
	}

	public function testLoadMoreCustomTriggerAndLabel(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				$this->anything(),
				$this->callback(fn (array $params): bool => ($params['trigger'] ?? '') === 'click'
						&& ($params['label'] ?? '') === 'Show More'),
				'click',
				'Show More',
				$this->anything(),
				$this->anything(),
			)
			->willReturn('<button>Show More</button>');

		$this->adapter->loadMore('blog', [
			'template' => 'blog/card',
			'trigger'  => 'click',
			'label'    => 'Show More',
		]);
	}

	public function testLoadMoreTransitionOption(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				$this->anything(),
				$this->callback(fn (array $params): bool => ($params['transition'] ?? '') === '1'),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				true,
			)
			->willReturn('<div>trigger</div>');

		$this->adapter->loadMore('blog', [
			'template'   => 'blog/card',
			'transition' => true,
		]);
	}

	public function testLoadMoreExtraClass(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				'my-class',
				$this->anything(),
			)
			->willReturn('<div>trigger</div>');

		$this->adapter->loadMore('blog', [
			'template' => 'blog/card',
			'class'    => 'my-class',
		]);
	}

	// --- loadMoreDataView ---

	public function testLoadMoreDataViewReturnsErrorWhenTemplateMissing(): void
	{
		$result = $this->adapter->loadMoreDataView('my-view');

		$this->assertStringContainsString('template', $result);
		$this->assertStringContainsString('<!--', $result);
	}

	public function testLoadMoreDataViewBuildsCorrectBaseUrl(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				'/api/dataviews/my-view/query',
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn('<div>trigger</div>');

		$this->adapter->loadMoreDataView('my-view', ['template' => 'cards/item']);
	}

	public function testLoadMoreDataViewPassesOptions(): void
	{
		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->with(
				$this->anything(),
				$this->callback(fn (array $params): bool => $params['template'] === 'cards/item'
						&& $params['limit'] === '6'
						&& $params['offset'] === '6'),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
			)
			->willReturn('<div>trigger</div>');

		$this->adapter->loadMoreDataView('my-view', [
			'template' => 'cards/item',
			'limit'    => 6,
		]);
	}
}
