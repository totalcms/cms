<?php

namespace Tests\Unit\Action\DataView;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\DataView\DataViewFetchAction;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\DataView\Service\DataViewFetcher;
use TotalCMS\Renderer\JsonRenderer;

final class DataViewFetchActionTest extends TestCase
{
	private DataViewFetchAction $action;
	private MockObject&JsonRenderer $renderer;
	private MockObject&DataViewFetcher $fetcher;
	private MockObject&DataViewBuilder $builder;
	private MockObject&ServerRequestInterface $request;
	private MockObject&ResponseInterface $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->fetcher  = $this->createMock(DataViewFetcher::class);
		$this->builder  = $this->createMock(DataViewBuilder::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new DataViewFetchAction(
			$this->renderer,
			$this->fetcher,
			$this->builder,
		);
	}

	public function testReturnsViewDataWhenComputedDataExists(): void
	{
		$viewData = ['items' => [1, 2, 3]];

		$this->fetcher->expects($this->once())
			->method('dataExists')
			->with('my-view')
			->willReturn(true);

		$this->builder->expects($this->never())
			->method('buildView');

		$this->fetcher->expects($this->once())
			->method('getViewData')
			->with('my-view')
			->willReturn($viewData);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $viewData)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, ['id' => 'my-view']);

		$this->assertSame($this->response, $result);
	}

	public function testBuildsViewFirstWhenDataDoesNotExist(): void
	{
		$viewData = ['built' => true];

		$this->fetcher->expects($this->once())
			->method('dataExists')
			->with('new-view')
			->willReturn(false);

		$this->builder->expects($this->once())
			->method('buildView')
			->with('new-view');

		$this->fetcher->expects($this->once())
			->method('getViewData')
			->with('new-view')
			->willReturn($viewData);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $viewData)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, ['id' => 'new-view']);

		$this->assertSame($this->response, $result);
	}
}
