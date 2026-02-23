<?php

namespace Tests\Unit\Action\DataView;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\DataView\DataViewRebuildAction;
use TotalCMS\Domain\DataView\Data\DataViewData;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Domain\Object\Data\ObjectData;
use TotalCMS\Domain\Object\Service\ObjectFetcher;
use TotalCMS\Renderer\JsonRenderer;

final class DataViewRebuildActionTest extends TestCase
{
	private DataViewRebuildAction $action;
	private MockObject&JsonRenderer $renderer;
	private MockObject&ObjectFetcher $objectFetcher;
	private MockObject&DataViewBuilder $builder;
	private MockObject&ServerRequestInterface $request;
	private MockObject&ResponseInterface $response;

	protected function setUp(): void
	{
		$this->renderer      = $this->createMock(JsonRenderer::class);
		$this->objectFetcher = $this->createMock(ObjectFetcher::class);
		$this->builder       = $this->createMock(DataViewBuilder::class);
		$this->request       = $this->createMock(ServerRequestInterface::class);
		$this->response      = $this->createMock(ResponseInterface::class);

		$this->action = new DataViewRebuildAction(
			$this->renderer,
			$this->objectFetcher,
			$this->builder,
		);
	}

	public function testVerifiesViewExistsBuildsAndReturnsUpdatedObject(): void
	{
		$updatedArray = [
			'id'         => 'rebuild-view',
			'definition' => 'some template',
			'lastBuilt'  => '2026-01-01T00:00:00+00:00',
			'lastError'  => '',
		];

		$initialObject = $this->createMock(ObjectData::class);
		$updatedObject = $this->createMock(ObjectData::class);
		$updatedObject->method('toArray')->willReturn($updatedArray);

		$this->objectFetcher->expects($this->exactly(2))
			->method('fetchObject')
			->with(DataViewData::COLLECTION_ID, 'rebuild-view')
			->willReturnOnConsecutiveCalls($initialObject, $updatedObject);

		$this->builder->expects($this->once())
			->method('buildView')
			->with('rebuild-view');

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $updatedArray)
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response, ['id' => 'rebuild-view']);

		$this->assertSame($this->response, $result);
	}
}
