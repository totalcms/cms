<?php

namespace Tests\Unit\Domain\Twig\Adapter;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TotalCMS\Domain\Collection\Service\CollectionFetcher;
use TotalCMS\Domain\Collection\Service\CollectionLister;
use TotalCMS\Domain\DataView\Service\DataViewQueryService;
use TotalCMS\Domain\Index\Service\IndexQueryService;
use TotalCMS\Domain\Query\Data\QueryResult;
use TotalCMS\Domain\Schema\Service\SchemaFetcher;
use TotalCMS\Domain\Twig\Adapter\DataTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\MediaTwigAdapter;
use TotalCMS\Domain\Twig\Adapter\RenderTwigAdapter;
use TotalCMS\Domain\Twig\Service\GridRenderer;
use TotalCMS\Domain\Twig\Service\HtmxRenderer;
use TotalCMS\Domain\Twig\Service\TwigEngine;
use TotalCMS\Factory\LoggerFactory;
use TotalCMS\Support\Config;

final class RenderTwigAdapterTest extends TestCase
{
	private MockObject&HtmxRenderer $htmxRenderer;
	private MockObject&IndexQueryService $indexQueryService;
	private MockObject&DataViewQueryService $dataViewQueryService;
	private RenderTwigAdapter $adapter;

	protected function setUp(): void
	{
		$this->htmxRenderer        = $this->createMock(HtmxRenderer::class);
		$this->indexQueryService    = $this->createMock(IndexQueryService::class);
		$this->dataViewQueryService = $this->createMock(DataViewQueryService::class);

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
			$this->createMock(GridRenderer::class),
			$loggerFactory,
			indexQueryService: $this->indexQueryService,
			dataViewQueryServiceFactory: fn () => $this->dataViewQueryService,
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

	// --- empty option ---

	public function testLoadMoreEmptyOptionReturnsEmptyHtmlWhenZeroResults(): void
	{
		$this->indexQueryService->expects($this->once())
			->method('query')
			->with('blog', $this->callback(fn (array $params): bool => $params['limit'] === '1' && $params['offset'] === '0'))
			->willReturn(new QueryResult([], 0, 1, 0));

		$this->htmxRenderer->expects($this->never())->method('buildInitialTrigger');

		$result = $this->adapter->loadMore('blog', [
			'template' => 'blog/card',
			'empty'    => 'No posts found.',
		]);

		$this->assertStringContainsString('cms-no-results', $result);
		$this->assertStringContainsString('No posts found.', $result);
	}

	public function testLoadMoreEmptyOptionReturnsTriggerWhenResultsExist(): void
	{
		$this->indexQueryService->expects($this->once())
			->method('query')
			->willReturn(new QueryResult([['id' => 'test']], 5, 1, 0));

		$this->htmxRenderer->expects($this->once())
			->method('buildInitialTrigger')
			->willReturn('<div>trigger</div>');

		$result = $this->adapter->loadMore('blog', [
			'template' => 'blog/card',
			'empty'    => 'No posts found.',
		]);

		$this->assertSame('<div>trigger</div>', $result);
	}

	public function testLoadMoreEmptyOptionForwardsFilterParams(): void
	{
		$this->indexQueryService->expects($this->once())
			->method('query')
			->with('blog', $this->callback(fn (array $params): bool => $params['include'] === 'published:true'
					&& $params['exclude'] === 'draft:true'
					&& $params['search'] === 'hello'))
			->willReturn(new QueryResult([], 0, 1, 0));

		$this->adapter->loadMore('blog', [
			'template' => 'blog/card',
			'empty'    => 'Nothing here.',
			'include'  => 'published:true',
			'exclude'  => 'draft:true',
			'search'   => 'hello',
		]);
	}

	public function testLoadMoreDataViewEmptyOptionReturnsEmptyHtmlWhenZeroResults(): void
	{
		$this->dataViewQueryService->expects($this->once())
			->method('query')
			->with('my-view', $this->callback(fn (array $params): bool => $params['limit'] === '1' && $params['offset'] === '0'))
			->willReturn(new QueryResult([], 0, 1, 0));

		$this->htmxRenderer->expects($this->never())->method('buildInitialTrigger');

		$result = $this->adapter->loadMoreDataView('my-view', [
			'template' => 'cards/item',
			'empty'    => '<p>No items</p>',
		]);

		$this->assertStringContainsString('cms-no-results', $result);
		$this->assertStringContainsString('<p>No items</p>', $result);
	}

	// --- load option ---

	private function buildAdapterWithTwigEngine(
		MockObject&HtmxRenderer $htmxRenderer,
		MockObject&IndexQueryService $indexQueryService,
		MockObject&DataViewQueryService $dataViewQueryService,
		MockObject&TwigEngine $twigEngine,
	): RenderTwigAdapter {
		$config      = $this->createMock(Config::class);
		$config->api = '/api';

		$loggerFactory = $this->createMock(LoggerFactory::class);
		$loggerFactory->method('addFileHandler')->willReturnSelf();
		$loggerFactory->method('createLogger')->willReturn(new \Psr\Log\NullLogger());

		return new RenderTwigAdapter(
			$htmxRenderer,
			$config,
			$this->createMock(DataTwigAdapter::class),
			$this->createMock(MediaTwigAdapter::class),
			$this->createMock(CollectionFetcher::class),
			$this->createMock(CollectionLister::class),
			$this->createMock(SchemaFetcher::class),
			$this->createMock(GridRenderer::class),
			$loggerFactory,
			indexQueryService: $indexQueryService,
			dataViewQueryServiceFactory: fn () => $dataViewQueryService,
			twigEngineFactory: fn () => $twigEngine,
		);
	}

	public function testLoadOptionRendersItemsAndTrigger(): void
	{
		$htmx        = $this->createMock(HtmxRenderer::class);
		$index       = $this->createMock(IndexQueryService::class);
		$dataView    = $this->createMock(DataViewQueryService::class);
		$twigEngine  = $this->createMock(TwigEngine::class);

		$items = [['id' => 'a', 'title' => 'A'], ['id' => 'b', 'title' => 'B']];

		$index->expects($this->once())
			->method('query')
			->with('blog', $this->callback(fn (array $p): bool => $p['limit'] === '2' && $p['offset'] === '0'))
			->willReturn(new QueryResult($items, 5, 2, 0));

		$twigEngine->expects($this->exactly(2))
			->method('render')
			->willReturnCallback(fn (string $tpl, array $data): string => '<div>' . $data['object']['id'] . '</div>');

		$htmx->expects($this->once())
			->method('buildInitialTrigger')
			->willReturn('<div>trigger</div>');

		$adapter = $this->buildAdapterWithTwigEngine($htmx, $index, $dataView, $twigEngine);

		$result = $adapter->loadMore('blog', [
			'template' => 'blog/card',
			'limit'    => 2,
			'load'     => true,
		]);

		$this->assertStringContainsString('<div>a</div>', $result);
		$this->assertStringContainsString('<div>b</div>', $result);
		$this->assertStringContainsString('<div>trigger</div>', $result);
	}

	public function testLoadOptionRendersItemsWithoutTriggerWhenNoMore(): void
	{
		$htmx        = $this->createMock(HtmxRenderer::class);
		$index       = $this->createMock(IndexQueryService::class);
		$dataView    = $this->createMock(DataViewQueryService::class);
		$twigEngine  = $this->createMock(TwigEngine::class);

		$items = [['id' => 'only']];

		$index->expects($this->once())
			->method('query')
			->willReturn(new QueryResult($items, 1, 10, 0));

		$twigEngine->expects($this->once())
			->method('render')
			->willReturn('<div>only</div>');

		$htmx->expects($this->never())->method('buildInitialTrigger');

		$adapter = $this->buildAdapterWithTwigEngine($htmx, $index, $dataView, $twigEngine);

		$result = $adapter->loadMore('blog', [
			'template' => 'blog/card',
			'limit'    => 10,
			'load'     => true,
		]);

		$this->assertSame('<div>only</div>', $result);
	}

	public function testLoadOptionWithEmptyAndZeroResultsReturnsEmptyHtml(): void
	{
		$htmx        = $this->createMock(HtmxRenderer::class);
		$index       = $this->createMock(IndexQueryService::class);
		$dataView    = $this->createMock(DataViewQueryService::class);
		$twigEngine  = $this->createMock(TwigEngine::class);

		// The empty check query runs first with limit=1
		$index->expects($this->once())
			->method('query')
			->willReturn(new QueryResult([], 0, 1, 0));

		$twigEngine->expects($this->never())->method('render');
		$htmx->expects($this->never())->method('buildInitialTrigger');

		$adapter = $this->buildAdapterWithTwigEngine($htmx, $index, $dataView, $twigEngine);

		$result = $adapter->loadMore('blog', [
			'template' => 'blog/card',
			'load'     => true,
			'empty'    => 'Nothing here.',
		]);

		$this->assertStringContainsString('cms-no-results', $result);
		$this->assertStringContainsString('Nothing here.', $result);
	}

	public function testLoadOptionDataViewRendersItemsAndTrigger(): void
	{
		$htmx        = $this->createMock(HtmxRenderer::class);
		$index       = $this->createMock(IndexQueryService::class);
		$dataView    = $this->createMock(DataViewQueryService::class);
		$twigEngine  = $this->createMock(TwigEngine::class);

		$items = [['id' => 'x']];

		$dataView->expects($this->once())
			->method('query')
			->with('my-view', $this->callback(fn (array $p): bool => $p['limit'] === '5' && $p['offset'] === '0'))
			->willReturn(new QueryResult($items, 10, 5, 0));

		$twigEngine->expects($this->once())
			->method('render')
			->willReturn('<div>x</div>');

		$htmx->expects($this->once())
			->method('buildInitialTrigger')
			->willReturn('<div>trigger</div>');

		$adapter = $this->buildAdapterWithTwigEngine($htmx, $index, $dataView, $twigEngine);

		$result = $adapter->loadMoreDataView('my-view', [
			'template' => 'cards/item',
			'limit'    => 5,
			'load'     => true,
		]);

		$this->assertStringContainsString('<div>x</div>', $result);
		$this->assertStringContainsString('<div>trigger</div>', $result);
	}
}
