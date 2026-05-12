<?php

namespace Tests\Unit\Action\DataView;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TotalCMS\Action\DataView\DataViewTestAction;
use TotalCMS\Domain\DataView\Service\DataViewBuilder;
use TotalCMS\Renderer\JsonRenderer;
use TotalCMS\Support\OperationResult;

final class DataViewTestActionTest extends TestCase
{
	private DataViewTestAction $action;
	private MockObject&JsonRenderer $renderer;
	private MockObject&DataViewBuilder $builder;
	private MockObject&ServerRequestInterface $request;
	private MockObject&ResponseInterface $response;

	protected function setUp(): void
	{
		$this->renderer = $this->createMock(JsonRenderer::class);
		$this->builder  = $this->createMock(DataViewBuilder::class);
		$this->request  = $this->createMock(ServerRequestInterface::class);
		$this->response = $this->createMock(ResponseInterface::class);

		$this->action = new DataViewTestAction(
			$this->renderer,
			$this->builder,
		);
	}

	public function testReturns400WhenDefinitionIsEmpty(): void
	{
		$this->request->method('getParsedBody')
			->willReturn(['definition' => '']);

		$errorResponse = $this->createMock(ResponseInterface::class);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, ['error' => 'Definition is required'])
			->willReturn($errorResponse);

		$errorResponse->expects($this->once())
			->method('withStatus')
			->with(400)
			->willReturn($errorResponse);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($errorResponse, $result);
	}

	public function testReturnsTestResultFromBuilderOnSuccess(): void
	{
		$definition = '{% set data = {"key": "value"} %}';
		$testResult = OperationResult::success('', [
			'data'   => ['key' => 'value'],
			'output' => '',
		]);

		$this->request->method('getParsedBody')
			->willReturn(['definition' => $definition]);

		$this->builder->expects($this->once())
			->method('testView')
			->with($definition)
			->willReturn($testResult);

		$this->renderer->expects($this->once())
			->method('json')
			->with($this->response, $testResult->toArray())
			->willReturn($this->response);

		$result = ($this->action)($this->request, $this->response);

		$this->assertSame($this->response, $result);
	}
}
