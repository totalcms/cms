<?php

namespace Tests\Unit\Action\Collection\Index;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\Collection\Index\IndexGetAction;
use TotalCMS\Domain\Index\Data\IndexData;
use TotalCMS\Domain\Index\Service\IndexFilter;
use TotalCMS\Domain\Index\Service\IndexReader;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Transformer\IndexTransformer;

final class IndexGetActionTest extends TestCase
{
	private IndexGetAction $action;
	private JsonRenderer $renderer;
	private IndexReader $indexReader;
	private IndexFilter $indexFilter;
	private ServerRequestInterface $request;
	private ResponseInterface $response;

	protected function setUp(): void
	{
		$this->renderer     = $this->createMock(JsonRenderer::class);
		$this->indexReader  = $this->createMock(IndexReader::class);
		$this->indexFilter  = $this->createMock(IndexFilter::class);
		$this->request      = $this->createMock(ServerRequestInterface::class);
		$this->response     = $this->createMock(ResponseInterface::class);

		$this->action = new IndexGetAction(
			$this->renderer,
			$this->indexReader,
			$this->indexFilter
		);
	}

	public function testFetchesIndexWithoutFilters(): void
	{
		$indexData = $this->createIndexData();

		$this->request->expects($this->once())
			->method('getQueryParams')
			->willReturn([]);

		$this->indexFilter->expects($this->once())
			->method('extractFilterOptions')
			->with([])
			->willReturn([]);

		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with('blog')
			->willReturn($indexData);

		$this->indexFilter->expects($this->never())
			->method('fetchFilteredIndexData');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with(
				$this->response,
				$indexData,
				$this->isInstanceOf(IndexTransformer::class)
			)
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog']);

		$this->assertSame($jsonResponse, $result);
	}

	public function testFetchesIndexWithIncludeFilter(): void
	{
		$indexData = $this->createIndexData();
		$params = ['include' => 'published:true'];
		$filterOptions = ['include' => 'published:true'];

		$this->request->expects($this->once())
			->method('getQueryParams')
			->willReturn($params);

		$this->indexFilter->expects($this->once())
			->method('extractFilterOptions')
			->with($params)
			->willReturn($filterOptions);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndexData')
			->with('blog', $filterOptions)
			->willReturn($indexData);

		$this->indexReader->expects($this->never())
			->method('fetchIndex');

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with($this->response, $indexData, $this->anything())
			->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog']);

		$this->assertSame($jsonResponse, $result);
	}

	public function testFetchesIndexWithExcludeFilter(): void
	{
		$indexData = $this->createIndexData();
		$params = ['exclude' => 'draft:true'];
		$filterOptions = ['exclude' => 'draft:true'];

		$this->request->expects($this->once())
			->method('getQueryParams')
			->willReturn($params);

		$this->indexFilter->expects($this->once())
			->method('extractFilterOptions')
			->with($params)
			->willReturn($filterOptions);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndexData')
			->with('blog', $filterOptions)
			->willReturn($indexData);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonItem')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testFetchesIndexWithBothFilters(): void
	{
		$indexData = $this->createIndexData();
		$params = ['include' => 'published:true', 'exclude' => 'draft:true'];
		$filterOptions = ['include' => 'published:true', 'exclude' => 'draft:true'];

		$this->request->expects($this->once())
			->method('getQueryParams')
			->willReturn($params);

		$this->indexFilter->expects($this->once())
			->method('extractFilterOptions')
			->with($params)
			->willReturn($filterOptions);

		$this->indexFilter->expects($this->once())
			->method('fetchFilteredIndexData')
			->with('blog', $filterOptions)
			->willReturn($indexData);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonItem')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testIgnoresNonFilterParams(): void
	{
		$indexData = $this->createIndexData();
		$params = ['limit' => '10', 'offset' => '0', 'sort' => 'date'];

		$this->request->expects($this->once())
			->method('getQueryParams')
			->willReturn($params);

		$this->indexFilter->expects($this->once())
			->method('extractFilterOptions')
			->with($params)
			->willReturn([]);

		$this->indexReader->expects($this->once())
			->method('fetchIndex')
			->with('products')
			->willReturn($indexData);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonItem')->willReturn($jsonResponse);

		($this->action)($this->request, $this->response, ['collection' => 'products']);
	}

	public function testUsesIndexTransformer(): void
	{
		$indexData = $this->createIndexData();

		$this->request->method('getQueryParams')->willReturn([]);
		$this->indexFilter->method('extractFilterOptions')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn($indexData);

		$this->renderer->expects($this->once())
			->method('jsonItem')
			->with(
				$this->anything(),
				$this->anything(),
				$this->isInstanceOf(IndexTransformer::class)
			)
			->willReturn($this->response);

		($this->action)($this->request, $this->response, ['collection' => 'blog']);
	}

	public function testReturnsResponseInterface(): void
	{
		$indexData = $this->createIndexData();

		$this->request->method('getQueryParams')->willReturn([]);
		$this->indexFilter->method('extractFilterOptions')->willReturn([]);
		$this->indexReader->method('fetchIndex')->willReturn($indexData);

		$jsonResponse = $this->createMock(ResponseInterface::class);
		$this->renderer->method('jsonItem')->willReturn($jsonResponse);

		$result = ($this->action)($this->request, $this->response, ['collection' => 'blog']);

		$this->assertInstanceOf(ResponseInterface::class, $result);
	}

	private function createIndexData(): IndexData
	{
		return new IndexData([
			['id' => '1', 'title' => 'Post 1'],
			['id' => '2', 'title' => 'Post 2'],
		]);
	}
}
